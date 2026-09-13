<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\ClaimedJob;
use App\Queue\JobHandler;
use App\Queue\JobOutcome;
use App\Queue\JobRepository;
use App\Services\QueueWorker;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class QueueWorkerRetryPolicyTest extends TestCase
{
    /** @dataProvider schedules */
    public function testRetryPersistsBoundedAvailableTimeWithoutSleeping(string $type, int $attempt, ?int $retryAfter, int $jitter, int $expectedDelay): void
    {
        $job = new ClaimedJob(51, 'media', $type, 11, [], 'worker', 'lease', $attempt, max(8, $attempt));
        $repository = new RetryScheduleRepository($job);
        $handler = new class($retryAfter) implements JobHandler {
            public function __construct(private ?int $retryAfter) {}
            public function handle(ClaimedJob $job): JobOutcome {
                return JobOutcome::retry('ai_unavailable', 'IA temporariamente indisponível.', $this->retryAfter);
            }
        };
        $worker = new QueueWorker($repository, [$type => $handler], 'worker', 120,
            static fn (): int => 1700000000, null, null,
            static fn (int $min, int $max): int => $jitter === 0 ? $min : $max);

        $report = $worker->run('media', 1, 10);

        self::assertSame(1, $repository->retryCalls);
        self::assertSame(1700000000 + $expectedDelay, $repository->availableAt?->getTimestamp());
        self::assertSame($attempt >= $job->maxAttempts() ? 1 : 0, $report->failed);
        self::assertSame($attempt < $job->maxAttempts() ? 1 : 0, $report->retried);
    }

    public function schedules(): iterable
    {
        yield 'analysis first lower jitter' => ['analyze_video', 1, null, 0, 15];
        yield 'analysis first upper jitter' => ['analyze_video', 1, null, 1, 18];
        yield 'analysis exponential upper jitter' => ['analyze_video', 3, null, 1, 75];
        yield 'provider minimum above backoff' => ['analyze_video', 2, 120, 1, 120];
        yield 'short provider hint cannot bypass backoff' => ['analyze_video', 3, 1, 1, 75];
        yield 'bounded provider hint' => ['analyze_video', 1, 900, 1, 900];
        yield 'bounded exponent for old unusual jobs' => ['analyze_video', 999, null, 1, 900];
        yield 'non analysis preserves its delay' => ['probe_source', 3, null, 1, 60];
    }

    /** @dataProvider invalidDelays */
    public function testRetryOutcomeRejectsUnsafeDelay(int $delay): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobOutcome::retry('ai_unavailable', 'Indisponível.', $delay);
    }

    public function invalidDelays(): iterable { yield [0]; yield [-1]; yield [901]; }
}

final class RetryScheduleRepository implements JobRepository
{
    public ?DateTimeImmutable $availableAt = null;
    public int $retryCalls = 0;
    public function __construct(private ?ClaimedJob $job) {}
    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob { $job = $this->job; $this->job = null; return $job; }
    public function failOneExpiredExhausted(string $queue): bool { return false; }
    public function complete(ClaimedJob $job): bool { return true; }
    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool {
        ++$this->retryCalls; $this->availableAt = $availableAt; return true;
    }
    public function defer(ClaimedJob $job, DateTimeImmutable $availableAt): bool { return true; }
    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool { return true; }
}
