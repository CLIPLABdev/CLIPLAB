<?php

declare(strict_types=1);

namespace App\Services;

use App\Queue\ClaimedJob;
use App\Queue\JobHandler;
use App\Queue\JobOutcome;
use App\Queue\JobRepository;
use App\Queue\ProcessingErrorCatalog;
use App\Queue\RetryPolicy;
use App\Queue\TransientJobFailure;
use App\Queue\WorkerReport;
use App\Queue\WorkerMaintenance;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class QueueWorker
{
    private const MAX_CONSECUTIVE_EMPTY_CLAIMS = 3;
    private const MAX_EXPIRED_LEASE_CLEANUPS = 3;

    /** @var array<string, JobHandler> */
    private array $handlers;
    /** @var callable(): int */
    private $clock;
    private ?\Closure $observer;
    private RetryPolicy $retryPolicy;

    /** @param array<string, JobHandler> $handlers */
    public function __construct(
        private JobRepository $jobs,
        array $handlers,
        private string $workerId,
        private int $leaseSeconds,
        ?callable $clock = null,
        private ?WorkerMaintenance $maintenance = null,
        ?callable $observer = null,
        ?callable $retryRandom = null
    )
    {
        if (trim($workerId) === '' || $leaseSeconds < 1) {
            throw new InvalidArgumentException('Worker id and lease duration are required.');
        }
        foreach ($handlers as $type => $handler) {
            if (!is_string($type) || !$handler instanceof JobHandler) {
                throw new InvalidArgumentException('Job handlers must be keyed by type.');
            }
        }
        $this->handlers = $handlers;
        $this->clock = $clock ?? static fn (): int => time();
        $this->observer = $observer === null ? null : \Closure::fromCallable($observer);
        $this->retryPolicy = new RetryPolicy($retryRandom);
    }

    public function run(string $queue, int $limit, int $timeBudgetSeconds): WorkerReport
    {
        if (trim($queue) === '' || $limit < 1 || $limit > 10 || $timeBudgetSeconds < 1) {
            throw new InvalidArgumentException('Queue, limit and time budget are invalid.');
        }
        $report = new WorkerReport();
        if ($this->maintenance !== null) {
            try {
                $this->maintenance->run();
            } catch (Throwable) {
                ++$report->operationalErrors;
            }
        }
        $deadline = ($this->clock)() + $timeBudgetSeconds;
        $emptyClaims = 0;
        $cleanedExpiredLeases = 0;
        $cleanupAvailable = true;
        while ($report->claimed < $limit && ($this->clock)() < $deadline) {
            if ($cleanupAvailable && $cleanedExpiredLeases < self::MAX_EXPIRED_LEASE_CLEANUPS) {
                try {
                    $cleanupAvailable = $this->jobs->failOneExpiredExhausted($queue);
                    if ($cleanupAvailable) {
                        ++$cleanedExpiredLeases;
                        ++$report->failed;
                    }
                } catch (Throwable) {
                    ++$report->operationalErrors;
                    // Preserve all running leases for the next worker/reconciliation pass.
                    $cleanupAvailable = false;
                }
            }
            if (($this->clock)() >= $deadline) {
                break;
            }
            $job = $this->jobs->claimNext($queue, $this->workerId, $this->leaseSeconds);
            if ($job === null) {
                if (++$emptyClaims >= self::MAX_CONSECUTIVE_EMPTY_CLAIMS) {
                    break;
                }

                continue;
            }
            $emptyClaims = 0;
            ++$report->claimed;
            $this->process($job, $report);
        }

        return $report;
    }

    private function observe(ClaimedJob $job, string $status, ?string $code): void
    {
        if ($this->observer === null) {
            return;
        }
        try {
            ($this->observer)([
                'job_id' => $job->id(),
                'project_id' => $job->projectId(),
                'job_type' => in_array($job->type(), ['probe_source','fetch_and_probe','analyze_video','generate_clips','generate_subtitles','render_clip','generate_thumbnail_candidates','render_thumbnail_design'], true) ? $job->type() : 'unknown',
                'status' => $status,
                'result_code' => $code !== null && ProcessingErrorCatalog::message($code) !== null ? $code : null,
            ]);
        } catch (Throwable) {
            // Observability is best-effort and never controls a job transition.
        }
    }

    private function process(ClaimedJob $job, WorkerReport $report): void
    {
        $handler = $this->handlers[$job->type()] ?? null;
        if ($handler === null) {
            $this->persistFailure($job, 'unsupported_job_type', 'Tipo de processamento não suportado.', $report);

            return;
        }

        try {
            $outcome = $handler->handle($job);
        } catch (Throwable $exception) {
            $this->persistHandlerException($job, $exception, $report);

            return;
        }

        $this->persistOutcome($job, $outcome, $report);
    }

    private function persistOutcome(ClaimedJob $job, JobOutcome $outcome, WorkerReport $report): void
    {
        if ($outcome->status() === 'completed') {
            try {
                if ($this->jobs->complete($job)) {
                    ++$report->completed;
                    $this->observe($job, 'completed', null);
                }
            } catch (Throwable) {
                ++$report->operationalErrors;
            }

            return;
        }

        if ($outcome->status() === 'retry') {
            $code = (string) $outcome->code();
            $message = (string) $outcome->publicMessage();
            $this->persistRetry($job, $code, $message, $report, $outcome->delaySeconds());

            return;
        }
        if ($outcome->status() === 'deferred') {
            try {
                $delaySeconds = $outcome->delaySeconds();
                if (is_int($delaySeconds) && $this->jobs->defer($job, $this->deferredAt($delaySeconds))) {
                    ++$report->deferred;
                }
            } catch (Throwable) {
                ++$report->operationalErrors;
            }

            return;
        }

        $code = (string) $outcome->code();
        $message = (string) $outcome->publicMessage();
        $this->persistFailure($job, $code, $message, $report);
    }

    private function persistHandlerException(ClaimedJob $job, Throwable $exception, WorkerReport $report): void
    {
        if ($exception instanceof TransientJobFailure) {
            $code = $exception->publicCode();
            $message = $this->transientMessage($code);
            if ($message !== null) {
                $this->persistRetry($job, $code, $message, $report);

                return;
            }
        }

        $this->persistFailure($job, 'worker_error', 'O processamento falhou. Tente novamente.', $report);
    }

    private function persistRetry(ClaimedJob $job, string $code, string $message, WorkerReport $report, ?int $retryAfterSeconds = null): void
    {
        try {
            if ($this->jobs->retry($job, $code, $message, $this->retryAt($job, $retryAfterSeconds))) {
                $this->observe($job, $job->attempts() >= $job->maxAttempts() ? 'failed' : 'retry', $code);
                if ($job->attempts() >= $job->maxAttempts()) {
                    ++$report->failed;
                } else {
                    ++$report->retried;
                }
            }
        } catch (Throwable) {
            ++$report->operationalErrors;
        }
    }

    private function persistFailure(ClaimedJob $job, string $code, string $message, WorkerReport $report): void
    {
        try {
            if ($this->jobs->fail($job, $code, $message)) {
                ++$report->failed;
                $this->observe($job, 'failed', $code);
            }
        } catch (Throwable) {
            ++$report->operationalErrors;
        }
    }

    private function retryAt(ClaimedJob $job, ?int $retryAfterSeconds): DateTimeImmutable
    {
        $seconds = $this->retryPolicy->delaySeconds($job, $retryAfterSeconds);

        return (new DateTimeImmutable('@' . (($this->clock)() + $seconds)))->setTimezone(new DateTimeZone('UTC'));
    }

    private function deferredAt(int $delaySeconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $delaySeconds . ' seconds');
    }

    private function transientMessage(string $code): ?string
    {
        return in_array($code, [
            'processor_unavailable',
            'process_timeout',
            'network_timeout',
            'download_unavailable',
            'ai_timeout',
            'ai_rate_limited',
            'ai_unavailable',
        ], true) ? ProcessingErrorCatalog::message($code) : null;
    }
}
