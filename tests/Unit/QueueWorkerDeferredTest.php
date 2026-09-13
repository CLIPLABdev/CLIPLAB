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

final class QueueWorkerDeferredTest extends TestCase
{
    public function testDeferredOutcomeDoesNotCountAsARetryOrFailure(): void
    {
        $repository = new DeferredRecordingRepository($this->job());
        $startedAt = time();
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new DeferredOutcomeHandler(JobOutcome::deferred(60))],
            'deferred-worker',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(1, $report->deferred);
        self::assertSame(0, $report->retried);
        self::assertSame(0, $report->failed);
        self::assertInstanceOf(DateTimeImmutable::class, $repository->availableAt);
        $delay = $repository->availableAt->getTimestamp() - $startedAt;
        self::assertGreaterThanOrEqual(60, $delay);
        self::assertLessThanOrEqual(61, $delay);
    }

    /** @dataProvider invalidDeferredDelays */
    public function testDeferredOutcomeRejectsDelaysOutsideTheBoundedRange(int $delay): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JobOutcome::deferred($delay);
    }

    /** @return iterable<string, array{int}> */
    public function invalidDeferredDelays(): iterable
    {
        yield 'below minimum' => [4];
        yield 'above maximum' => [301];
    }

    private function job(): ClaimedJob
    {
        return new ClaimedJob(17, 'media', 'probe_source', 9, [], 'deferred-worker', str_repeat('a', 64), 3, 3);
    }
}

final class DeferredOutcomeHandler implements JobHandler
{
    public function __construct(private JobOutcome $outcome)
    {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        return $this->outcome;
    }
}

final class DeferredRecordingRepository implements JobRepository
{
    public ?DateTimeImmutable $availableAt = null;
    private bool $claimed = false;

    public function __construct(private ClaimedJob $job)
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

    public function failOneExpiredExhausted(string $queue): bool { return false; }
    public function complete(ClaimedJob $job): bool { return true; }
    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool { return true; }

    public function defer(ClaimedJob $job, mixed ...$arguments): bool
    {
        $availableAt = $arguments[count($arguments) - 1] ?? null;
        $this->availableAt = $availableAt instanceof DateTimeImmutable ? $availableAt : null;

        return true;
    }

    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool { return true; }
}
