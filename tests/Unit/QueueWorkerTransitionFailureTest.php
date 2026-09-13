<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\ClaimedJob;
use App\Queue\JobHandler;
use App\Queue\JobOutcome;
use App\Queue\JobRepository;
use App\Queue\TransientJobException;
use App\Services\QueueWorker;
use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueWorkerTransitionFailureTest extends TestCase
{
    public function testCompletionTransitionExceptionLeavesLeaseForRecoveryWithoutTerminalFallback(): void
    {
        $repository = new TransitionFailureRepository($this->job(), 'complete');
        $report = $this->worker($repository, new TransitionOutcomeHandler(JobOutcome::completed()))->run('media', 1, 50);

        self::assertSame(['complete'], $repository->transitions);
        self::assertSame(0, $report->completed);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->operationalErrors);
    }

    public function testRetryTransitionExceptionLeavesLeaseForRecoveryWithoutTerminalFallback(): void
    {
        $repository = new TransitionFailureRepository($this->job(), 'retry');
        $outcome = JobOutcome::retry('processor_unavailable', 'Processador temporariamente indisponível.');
        $report = $this->worker($repository, new TransitionOutcomeHandler($outcome))->run('media', 1, 50);

        self::assertSame(['retry'], $repository->transitions);
        self::assertSame(0, $report->retried);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->operationalErrors);
    }

    public function testDeferredTransitionExceptionLeavesLeaseForRecoveryWithoutTerminalFallback(): void
    {
        $repository = new TransitionFailureRepository($this->job(), 'defer');
        $outcome = JobOutcome::deferred(15);
        $report = $this->worker($repository, new TransitionOutcomeHandler($outcome))->run('media', 1, 50);

        self::assertSame(['defer'], $repository->transitions);
        self::assertSame(0, $report->retried);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->operationalErrors);
    }

    public function testFailureTransitionExceptionWhileHandlingThrowableLeavesLeaseForRecovery(): void
    {
        $repository = new TransitionFailureRepository($this->job(), 'fail');
        $report = $this->worker($repository, new TransitionThrowableHandler(new RuntimeException('handler fault')))->run('media', 1, 50);

        self::assertSame(['fail'], $repository->transitions);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->operationalErrors);
    }

    public function testTransientHandlerRetryTransitionExceptionLeavesLeaseForRecovery(): void
    {
        $repository = new TransitionFailureRepository($this->job(), 'retry');
        $report = $this->worker($repository, new TransitionThrowableHandler(new TransientJobException('processor_unavailable')))->run('media', 1, 50);

        self::assertSame(['retry'], $repository->transitions);
        self::assertSame(0, $report->retried);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->operationalErrors);
    }

    public function testExpiredLeaseReconciliationExceptionIsReportedAndDoesNotBlockAClaim(): void
    {
        $repository = new TransitionFailureRepository($this->job(), null, new PDOException('deadlock during reconciliation'));
        $report = $this->worker($repository, new TransitionOutcomeHandler(JobOutcome::completed()))->run('media', 1, 50);

        self::assertSame(1, $repository->cleanupCalls);
        self::assertSame(['complete'], $repository->transitions);
        self::assertSame(1, $report->completed);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->operationalErrors);
    }

    private function worker(JobRepository $repository, JobHandler $handler): QueueWorker
    {
        return new QueueWorker($repository, ['probe_source' => $handler], 'transition-test', 60, static fn (): int => 0);
    }

    private function job(): ClaimedJob
    {
        return new ClaimedJob(91, 'media', 'probe_source', 12, [], 'transition-test', str_repeat('a', 64), 1, 3);
    }
}

final class TransitionOutcomeHandler implements JobHandler
{
    public function __construct(private JobOutcome $outcome)
    {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        return $this->outcome;
    }
}

final class TransitionThrowableHandler implements JobHandler
{
    public function __construct(private \Throwable $exception)
    {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        throw $this->exception;
    }
}

final class TransitionFailureRepository implements JobRepository
{
    /** @var list<string> */
    public array $transitions = [];
    public int $cleanupCalls = 0;
    private bool $claimed = false;

    public function __construct(private ClaimedJob $job, private ?string $throwOnTransition, private ?\Throwable $cleanupFailure = null)
    {
    }

    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        if ($this->claimed) {
            return null;
        }

        $this->claimed = true;

        return $this->job;
    }

    public function failOneExpiredExhausted(string $queue): bool
    {
        ++$this->cleanupCalls;
        if ($this->cleanupFailure !== null) {
            throw $this->cleanupFailure;
        }

        return false;
    }

    public function complete(ClaimedJob $job): bool
    {
        return $this->transition('complete');
    }

    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool
    {
        return $this->transition('retry');
    }

    public function defer(ClaimedJob $job, DateTimeImmutable $availableAt): bool
    {
        return $this->transition('defer');
    }

    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool
    {
        return $this->transition('fail');
    }

    private function transition(string $transition): bool
    {
        $this->transitions[] = $transition;
        if ($transition === $this->throwOnTransition) {
            throw new PDOException('database transition failed');
        }

        return true;
    }
}
