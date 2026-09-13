<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\ClaimedJob;
use App\Queue\JobHandler;
use App\Queue\JobOutcome;
use App\Queue\JobRepository;
use App\Queue\WorkerMaintenance;

use App\Services\QueueWorker;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class QueueWorkerTest extends TestCase
{
    public function testThumbnailJobsKeepTheirIdentityInObservedEvents(): void
    {
        foreach (['generate_thumbnail_candidates','render_thumbnail_design'] as $type) {
            $events=[];
            $worker=new QueueWorker(new InMemoryJobRepository([$this->job(1,$type)]),
                [$type=>new FixedOutcomeHandler(JobOutcome::completed())],'worker',120,static fn(): int=>0,null,
                static function(array $event) use (&$events): void {$events[]=$event;});
            self::assertSame(1,$worker->run('media',1,50)->completed);
            self::assertSame($type,$events[0]['job_type']);
        }
    }
    public function testObserverReceivesOnlyAllowlistedOutcomeMetadataAndCannotBreakTheWorker(): void
    {
        $observed=[];
        $observer=static function(array $event) use (&$observed): void { $observed[]=$event; throw new \RuntimeException('logger unavailable'); };
        $worker=new QueueWorker(new InMemoryJobRepository([$this->job(1,'probe_source')]),
            ['probe_source'=>new FixedOutcomeHandler(JobOutcome::completed())],'worker',120,static fn(): int=>0,null,$observer);
        $report=$worker->run('media',1,50);
        self::assertSame(1,$report->completed);
        self::assertCount(1,$observed);
        self::assertSame(['job_id','project_id','job_type','status','result_code'],array_keys($observed[0]));
        self::assertSame('completed',$observed[0]['status']);
        self::assertNull($observed[0]['result_code']);
    }

    public function testRunsMaintenanceOnceEvenWhenTheQueueIsEmpty(): void
    {
        $maintenance = new RecordingWorkerMaintenance();
        $worker = new QueueWorker(
            new InMemoryJobRepository([]),
            [],
            'worker-test',
            120,
            static fn (): int => 0,
            $maintenance
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(1, $maintenance->calls);
        self::assertSame(0, $report->claimed);
        self::assertSame(0, $report->operationalErrors);
    }

    public function testRetriesTransientOutcomeAndContinuesUntilLimit(): void
    {
        $repository = new InMemoryJobRepository([
            $this->job(1, 'probe_source'),
            $this->job(2, 'probe_source'),
        ]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new FixedOutcomeHandler(JobOutcome::retry('processor_unavailable', 'Processador temporariamente indisponível.'))],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->retried);
        self::assertSame(0, $report->completed);
        self::assertSame(0, $report->failed);
    }

    public function testDefersPersistenceFailureWithoutTerminalizingAnExhaustedJob(): void
    {
        $repository = new InMemoryJobRepository([$this->job(1, 'probe_source', 3, 3)]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new FixedOutcomeHandler(JobOutcome::deferred(15))],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(0, $report->retried);
        self::assertSame(1, $report->deferred);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $repository->deferCalls);
    }

    public function testLeavesLeaseEligibleForReconciliationWhenDeferredTransitionCannotBePersisted(): void
    {
        $repository = new InMemoryJobRepository([$this->job(1, 'probe_source', 3, 3)]);
        $repository->deferFailure = new \PDOException('database offline');
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new FixedOutcomeHandler(JobOutcome::deferred(15))],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(0, $report->failed);
        self::assertSame(0, $repository->failCalls);
    }

    public function testStopsBeforeStartingAnotherJobAfterTimeBudget(): void
    {
        $times = [0, 49, 49, 51];
        $repository = new InMemoryJobRepository([
            $this->job(1, 'probe_source'),
            $this->job(2, 'probe_source'),
        ]);
        $worker = new QueueWorker(
            $repository,
            ['probe_source' => new FixedOutcomeHandler(JobOutcome::completed())],
            'worker-test',
            120,
            static function () use (&$times): int {
                return array_shift($times) ?? 51;
            }
        );

        $report = $worker->run('media', 10, 50);

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->completed);
    }

    public function testFailsUnknownTypesWithoutPassingPayloadToTheReport(): void
    {
        $repository = new InMemoryJobRepository([$this->job(1, 'unrecognized_type')]);
        $worker = new QueueWorker($repository, [], 'worker-test', 120, static fn (): int => 0);

        $report = $worker->run('media', 1, 50);

        self::assertSame(1, $report->failed);
        self::assertObjectNotHasProperty('payload', $report);
        self::assertObjectNotHasProperty('leaseToken', $report);
    }

    private function job(int $id, string $type, int $attempts = 1, int $maxAttempts = 3): ClaimedJob
    {
        return new ClaimedJob($id, 'media', $type, 9, ['source_id' => 7], 'worker-test', str_repeat('a', 64), $attempts, $maxAttempts);
    }
}

final class RecordingWorkerMaintenance implements WorkerMaintenance
{
    public int $calls = 0;

    public function run(): void
    {
        ++$this->calls;
    }
}

final class FixedOutcomeHandler implements JobHandler
{
    public function __construct(private JobOutcome $outcome)
    {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        return $this->outcome;
    }
}

final class InMemoryJobRepository implements JobRepository
{
        public int $deferCalls = 0;
    public int $failCalls = 0;
    public ?\Throwable $deferFailure = null;

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
        ++$this->deferCalls;
        if ($this->deferFailure !== null) {
            throw $this->deferFailure;
        }
        return true;
    }

    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool
    {
        return true;
    }
}
