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

final class QueueWorkerBoundedClaimTest extends TestCase
{
    public function testContinuesAfterOneBoundedExpiredLeaseCleanupSignal(): void
    {
        $repository = new CleanupSignalRepository([null, $this->job()]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new BoundedClaimCompletedHandler()],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(2, $repository->claimCalls);
        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->completed);
    }

    public function testStopsAfterThreeConsecutiveNullClaims(): void
    {
        $repository = new CleanupSignalRepository([null, null, null, null]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new BoundedClaimCompletedHandler()],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(3, $repository->claimCalls);
        self::assertSame(0, $report->claimed);
    }

    public function testStopsCleanupAfterThreeExpiredLeasesWhenNoJobIsAvailable(): void
    {
        $repository = new CleanupSignalRepository([null, null, null, null], [true, true, true, true]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new BoundedClaimCompletedHandler()],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(3, $repository->cleanupCalls);
        self::assertSame(3, $repository->claimCalls);
        self::assertSame(3, $report->failed);
        self::assertSame(0, $report->claimed);
    }
    public function testRechecksDeadlineAfterCleanupBeforeClaim(): void
    {
        $repository = new CleanupSignalRepository([$this->job()], [true]);
        $ticks = [0, 4, 6];
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new BoundedClaimCompletedHandler()],
            'worker-test',
            120,
            static function () use (&$ticks): int {
                return array_shift($ticks);
            }
        );

        $report = $worker->run('media', 1, 5);

        self::assertSame(1, $repository->cleanupCalls);
        self::assertSame(0, $repository->claimCalls);
        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->claimed);
    }

    private function job(): ClaimedJob
    {
        return new ClaimedJob(1, 'media', 'probe_source', 9, [], 'worker-test', str_repeat('d', 64), 1, 3);
    }
}

final class BoundedClaimCompletedHandler implements JobHandler
{
    public function handle(ClaimedJob $job): JobOutcome
    {
        return JobOutcome::completed();
    }
}

final class CleanupSignalRepository implements JobRepository
{
    /** @var list<ClaimedJob|null> */
    private array $claims;
    public int $claimCalls = 0;
    public int $cleanupCalls = 0;

    /** @param list<ClaimedJob|null> $claims */
    /** @param list<bool> $cleanupResults */
    public function __construct(array $claims, private array $cleanupResults = [])
    {
        $this->claims = $claims;
    }

    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        ++$this->claimCalls;

        return array_shift($this->claims);
    }

    public function failOneExpiredExhausted(string $queue): bool
    {
        ++$this->cleanupCalls;

        return array_shift($this->cleanupResults) ?? false;
    }
    public function complete(ClaimedJob $job): bool
    {
        return true;
    }

    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool
    {
        return true;
    }

    public function defer(ClaimedJob $job, DateTimeImmutable $availableAt): bool
    {
        return true;
    }

    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool
    {
        return true;
    }
}
