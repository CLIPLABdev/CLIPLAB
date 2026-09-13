<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\ClaimedJob;
use App\Queue\JobHandler;
use App\Queue\JobOutcome;
use App\Queue\JobRepository;

use App\Queue\TransientJobException;
use App\Queue\TransientJobFailure;
use App\Services\QueueWorker;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueWorkerHardeningTest extends TestCase
{
    public function testReportsFailedWhenRetryTransitionTerminalizesTheJob(): void
    {
        $repository = new TerminalRetryRepository([$this->job(1, 3)]);
        $worker = new QueueWorker($repository, ['probe_source' => new RetryHandler()], 'worker-test', 120, static fn (): int => 0);

        $report = $worker->run('media', 1, 50);

        self::assertSame(0, $report->retried);
        self::assertSame(1, $report->failed);
    }

    public function testRetriesOnlyADeclaredTransientJobException(): void
    {
        $repository = new TerminalRetryRepository([$this->job(1, 1)]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new ThrowingHandler(new TransientJobException('processor_unavailable'))],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(1, $report->retried);
        self::assertSame(0, $report->failed);
    }


    public function testFailsAnUnrecognizedTransientFailureCode(): void
    {
        $repository = new TerminalRetryRepository([$this->job(1, 1)]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new ThrowingHandler(new UnrecognizedTransientFailure())],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(0, $report->retried);
        self::assertSame(1, $report->failed);
    }

    public function testSchedulesRetriesWithCappedExponentialBackoff(): void
    {
        $repository = new RecordingRetryRepository([
            $this->job(1, 1),
            $this->job(2, 2),
            new ClaimedJob(3, 'media', 'probe_source', 9, [], 'worker-test', str_repeat('c', 64), 7, 8),
        ]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new RetryHandler()],
            'worker-test',
            120,
            static fn (): int => 0
        );
        $report = $worker->run('media', 3, 50);

        self::assertSame(3, $report->retried);
        self::assertSame(3, count($repository->availableAts));
        foreach ([15, 30, 900] as $index => $seconds) {
            self::assertSame($seconds, $repository->availableAts[$index]->getTimestamp());
        }
    }
    private function job(int $id, int $attempts): ClaimedJob
    {
        return new ClaimedJob($id, 'media', 'probe_source', 9, [], 'worker-test', str_repeat('b', 64), $attempts, 3);
    }
}

final class RetryHandler implements JobHandler
{
    public function handle(ClaimedJob $job): JobOutcome
    {
        return JobOutcome::retry('processor_unavailable', 'Processador temporariamente indisponível.');
    }
}

final class ThrowingHandler implements JobHandler
{
    public function __construct(private \Throwable $exception)
    {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        throw $this->exception;
    }
}

final class UnrecognizedTransientFailure extends RuntimeException implements TransientJobFailure
{
    public function publicCode(): string
    {
        return 'unrecognized_transient_failure';
    }
}

final class TerminalRetryRepository implements JobRepository
{
    /** @param list<ClaimedJob> $jobs */
    public function __construct(private array $jobs)
    {
    }

    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        return array_shift($this->jobs);
    }

    public function failOneExpiredExhausted(string $queue): bool
    {
        return false;
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

final class RecordingRetryRepository implements JobRepository
{
    /** @var list<ClaimedJob> */
    private array $jobs;
    /** @var list<DateTimeImmutable> */
    public array $availableAts = [];

    /** @param list<ClaimedJob> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        return array_shift($this->jobs);
    }

    public function failOneExpiredExhausted(string $queue): bool
    {
        return false;
    }
    public function complete(ClaimedJob $job): bool
    {
        return true;
    }

    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool
    {
        $this->availableAts[] = $availableAt;

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
