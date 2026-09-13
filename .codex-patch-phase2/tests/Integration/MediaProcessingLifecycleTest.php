<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\MediaProcessor;
use App\Contracts\PrivateStorage;
use App\Core\Migrator;
use App\Exceptions\MediaValidationException;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Process\ProcessExecutionException;
use App\Queue\FetchAndProbeHandler;
use App\Queue\ClaimedJob;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\JobRepository;
use App\Queue\ProbeSourceHandler;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\QueueWorker;
use PDO;
use PDOException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MediaProcessingLifecycleTest extends TestCase
{
    private PDO $pdo;
    private ProcessingJobRepository $jobs;
    private int $userId;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO((string) $dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute(['Media lifecycle', 'lifecycle-' . $suffix . '@example.test', 'x', $planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->jobs = new ProcessingJobRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->userId)) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
    }

    public function testWorkerPersistsAHandlerPersistenceRetryUsingThePublicCatalog(): void
    {
        $projectId = $this->project('queued');
        $this->job($projectId, 'probe_source');
        $sources = new class {
            public function findForProject(int $projectId): ?ProjectSource { throw new PDOException('database offline'); }
        };
        $handler = new ProbeSourceHandler(new LifecycleProjects(), $sources, new LifecycleProcessor(), new LeaseProcessingEffectGuard($this->pdo));

        $report = (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $report->retried);
        self::assertSame(['retry', 'processing_persistence_failed'], $this->jobState());
    }

    public function testFinalTransientAttemptTerminalizesSourceAndProjectBeforeWorkerFailsJob(): void
    {
        $projectId = $this->project('queued');
        $sourceId = $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source', 1);
        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LifecycleProcessor(null, new ProcessExecutionException('process_unavailable')), new LeaseProcessingEffectGuard($this->pdo));

        $report = (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $report->failed);
        self::assertSame('failed', $this->projectState($projectId));
        self::assertSame('failed', $this->sourceState($sourceId));
        self::assertSame(['failed', 'processor_unavailable'], $this->jobState());
    }

    public function testReadyProbeReplayAcknowledgesWithoutReopeningTheProject(): void
    {
        $projectId = $this->project('ready');
        $sourceId = $this->source($projectId, 'upload', 'ready');
        $this->job($projectId, 'probe_source');
        $processor = new LifecycleProcessor(null, new \RuntimeException('Ready source must not be probed again.'));
        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), $processor, new LeaseProcessingEffectGuard($this->pdo));

        $report = (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $report->completed);
        self::assertSame(0, $processor->calls);
        self::assertSame('ready', $this->projectState($projectId));
        self::assertSame('ready', $this->sourceState($sourceId));
        self::assertSame(['completed', null], $this->jobState());
    }

    public function testReadyReplayCompletesAfterCompletionTransitionPersistenceFails(): void
    {
        $projectId = $this->project('ready');
        $sourceId = $this->source($projectId, 'upload', 'ready');
        $this->job($projectId, 'probe_source');
        $jobId = (int) $this->pdo->query('SELECT id FROM processing_jobs ORDER BY id DESC LIMIT 1')->fetchColumn();
        $processor = new LifecycleProcessor(null, new \RuntimeException('Ready source must not be probed again.'));
        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), $processor, new LeaseProcessingEffectGuard($this->pdo));

        $first = (new QueueWorker(new LifecycleTransitionFailureRepository($this->jobs, 'complete'), ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $first->operationalErrors);
        self::assertSame(['running', null], $this->jobState());
        $this->pdo->prepare('UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$jobId]);

        $replay = (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle-replay', 60))->run('media', 1, 10);

        self::assertSame(1, $replay->completed);
        self::assertSame(0, $processor->calls);
        self::assertSame('ready', $this->projectState($projectId));
        self::assertSame('ready', $this->sourceState($sourceId));
        self::assertSame(['completed', null], $this->jobState());
    }

    public function testRetryReplayRemainsEligibleAfterRetryTransitionPersistenceFails(): void
    {
        $projectId = $this->project('queued');
        $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source');
        $jobId = (int) $this->pdo->query('SELECT id FROM processing_jobs ORDER BY id DESC LIMIT 1')->fetchColumn();
        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LifecycleProcessor(null, new ProcessExecutionException('process_unavailable')), new LeaseProcessingEffectGuard($this->pdo));

        $first = (new QueueWorker(new LifecycleTransitionFailureRepository($this->jobs, 'retry'), ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $first->operationalErrors);
        self::assertSame(['running', null], $this->jobState());
        $this->pdo->prepare('UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$jobId]);

        $replay = (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle-replay', 60))->run('media', 1, 10);

        self::assertSame(1, $replay->retried);
        self::assertSame(['retry', 'processor_unavailable'], $this->jobState());
    }

    public function testStoredDirectSourceReplayProbesWithoutDownloadingAgain(): void
    {
        $projectId = $this->project('fetching');
        $this->source($projectId, 'direct_url', 'stored');
        $this->job($projectId, 'fetch_and_probe');
        $downloader = new LifecycleDownloader();
        $handler = new FetchAndProbeHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), $downloader, new LifecycleStorage(), new LifecycleProcessor($this->metadata()), new LeaseProcessingEffectGuard($this->pdo), 1024);

        $report = (new QueueWorker($this->jobs, ['fetch_and_probe' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $report->completed);
        self::assertSame(0, $downloader->calls);
        self::assertSame('ready', $this->projectState($projectId));
    }

    public function testKnownMediaValidationMapsToTheStablePublicFailureCode(): void
    {
        $projectId = $this->project('queued');
        $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source');
        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LifecycleProcessor(null, MediaValidationException::withCode('invalid_media_container')), new LeaseProcessingEffectGuard($this->pdo));

        (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(['failed', 'invalid_media'], $this->jobState());
    }

    public function testUnexpectedProcessorThrowableIsTerminalInsteadOfPretendingTheProcessorIsUnavailable(): void
    {
        $projectId = $this->project('queued');
        $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source');
        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LifecycleProcessor(null, new \RuntimeException('unexpected')), new LeaseProcessingEffectGuard($this->pdo));

        (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(['failed', 'worker_error'], $this->jobState());
    }

    public function testWorkerDefersAnExhaustedJobWhenTerminalStatePersistenceDeadlocks(): void
    {
        $projectId = $this->project('queued');
        $sourceId = $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source', 1);
        $projects = new class {
            public function updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void
            {
                if ($status === 'failed') {
                    throw new PDOException('Deadlock found when trying to get lock.');
                }
            }
        };
        $handler = new ProbeSourceHandler($projects, new ProjectSourceRepository($this->pdo), new LifecycleProcessor(null, MediaValidationException::withCode('invalid_media_container')), new LeaseProcessingEffectGuard($this->pdo));

        $report = (new QueueWorker($this->jobs, ['probe_source' => $handler], 'worker-lifecycle', 60))->run('media', 1, 10);

        self::assertSame(1, $report->retried);
        self::assertSame(['retry', 'processing_persistence_failed'], $this->jobState());
        self::assertSame('queued', $this->projectState($projectId));
        self::assertSame('stored', $this->sourceState($sourceId));
    }

    public function testStaleClaimCannotMarkSourceOrProjectReadyAfterReplacement(): void
    {
        $projectId = $this->project('queued');
        $sourceId = $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source');
        $stale = $this->jobs->claimNext('media', 'worker-stale', 60);
        self::assertNotNull($stale);
        $this->pdo->prepare("UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?")
            ->execute([$stale->id()]);
        $replacement = (new ProcessingJobRepository(new PDO((string) getenv('TEST_DB_DSN'), getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])))
            ->claimNext('media', 'worker-current', 60);
        self::assertNotNull($replacement);

        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LifecycleProcessor($this->metadata()), new LeaseProcessingEffectGuard($this->pdo));
        $handler->handle($stale);

        self::assertSame('queued', $this->projectState($projectId));
        self::assertSame('stored', $this->sourceState($sourceId));
    }

    public function testStaleClaimCannotMarkSourceOrProjectFailedAfterReplacement(): void
    {
        $projectId = $this->project('queued');
        $sourceId = $this->source($projectId, 'upload', 'stored');
        $this->job($projectId, 'probe_source');
        $stale = $this->jobs->claimNext('media', 'worker-stale', 60);
        self::assertNotNull($stale);
        $this->pdo->prepare("UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?")
            ->execute([$stale->id()]);
        $replacement = (new ProcessingJobRepository(new PDO((string) getenv('TEST_DB_DSN'), getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])))
            ->claimNext('media', 'worker-current', 60);
        self::assertNotNull($replacement);

        $handler = new ProbeSourceHandler(new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LifecycleProcessor(null, MediaValidationException::withCode('invalid_media_container')), new LeaseProcessingEffectGuard($this->pdo));
        $handler->handle($stale);

        self::assertSame('queued', $this->projectState($projectId));
        self::assertSame('stored', $this->sourceState($sourceId));
    }

    private function project(string $status): int
    {
        $this->pdo->prepare('INSERT INTO projects (user_id, name, status, progress) VALUES (?, ?, ?, ?)')
            ->execute([$this->userId, 'Lifecycle project', $status, $status === 'ready' ? 100 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function source(int $projectId, string $type, string $status): int
    {
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, extension, mime_type, status) VALUES (?, ?, 'local', ?, 'mp4', 'video/mp4', ?)")
            ->execute([$projectId, $type, 'imports/' . $projectId . '/video.mp4', $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function job(int $projectId, string $type, int $maxAttempts = 3): void
    {
        $this->pdo->prepare('INSERT INTO processing_jobs (queue_name, type, project_id, payload_json, idempotency_key, max_attempts, available_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute(['media', $type, $projectId, '{}', hash('sha256', $type . ':' . $projectId . ':' . random_bytes(8)), $maxAttempts]);
    }

    /** @return array{0: string, 1: string|null} */
    private function jobState(): array
    {
        $row = $this->pdo->query('SELECT status, last_error_code FROM processing_jobs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return [(string) $row['status'], $row['last_error_code'] === null ? null : (string) $row['last_error_code']];
    }

    private function projectState(int $projectId): string
    {
        return (string) $this->pdo->query('SELECT status FROM projects WHERE id = ' . $projectId)->fetchColumn();
    }

    private function sourceState(int $sourceId): string
    {
        return (string) $this->pdo->query('SELECT status FROM project_sources WHERE id = ' . $sourceId)->fetchColumn();
    }

    private function metadata(): MediaMetadata
    {
        return new MediaMetadata(12, 1280, 720, 'h264', null, false);
    }
}

final class LifecycleProjects
{
    public function updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void
    {
    }
}

final class LifecycleProcessor implements MediaProcessor
{
    public int $calls = 0;

    public function __construct(private ?MediaMetadata $metadata = null, private ?\Throwable $exception = null)
    {
    }

    public function inspect(ProjectSource $source): MediaMetadata
    {
        ++$this->calls;
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->metadata ?? new MediaMetadata(12, 1280, 720, 'h264', null, false);
    }
}

final class LifecycleDownloader
{
    public int $calls = 0;

    public function downloadStoredUrl(string $url, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject
    {
        ++$this->calls;
        throw new \LogicException('Stored sources must not be downloaded again.');
    }
}

final class LifecycleStorage implements PrivateStorage
{
    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject { throw new \LogicException('Not used.'); }
    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject { throw new \LogicException('Not used.'); }
    public function absolutePath(string $objectKey): string { return 'C:/private/' . $objectKey; }
    public function delete(string $objectKey): void { }
}

final class LifecycleTransitionFailureRepository implements JobRepository
{
    public function __construct(private JobRepository $inner, private string $throwOn)
    {
    }

    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        return $this->inner->claimNext($queue, $workerId, $leaseSeconds);
    }

    public function failOneExpiredExhausted(string $queue): bool
    {
        return $this->inner->failOneExpiredExhausted($queue);
    }

    public function complete(ClaimedJob $job): bool
    {
        return $this->transition('complete', fn (): bool => $this->inner->complete($job));
    }

    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool
    {
        return $this->transition('retry', fn (): bool => $this->inner->retry($job, $code, $publicMessage, $availableAt));
    }

    public function defer(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool
    {
        return $this->transition('defer', fn (): bool => $this->inner->defer($job, $code, $publicMessage, $availableAt));
    }

    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool
    {
        return $this->transition('fail', fn (): bool => $this->inner->fail($job, $code, $publicMessage));
    }

    private function transition(string $name, callable $persist): bool
    {
        if ($name === $this->throwOn) {
            throw new PDOException('transition persistence failure');
        }

        return $persist();
    }
}
