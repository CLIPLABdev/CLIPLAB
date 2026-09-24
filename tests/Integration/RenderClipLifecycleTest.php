<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\ClipRenderer;
use App\Contracts\ClipRenderProfileStore;
use App\Contracts\PrivateStorage;
use App\Contracts\RenderArtifactCleanupStore;
use App\Core\Migrator;
use App\Exceptions\ClipRenderException;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Media\RenderClipRequest;
use App\Media\RenderedClipArtifacts;
use App\Queue\ClaimedJob;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\ProcessingEffectGuard;
use App\Queue\RenderClipHandler;
use App\Repositories\ClipRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\RenderArtifactCleanupRepository;
use App\Services\ClipRenderRequestService;
use App\Services\DatabaseJobDispatcher;
use App\Services\QueueWorker;
use App\Services\RenderMaintenance;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\SafePhase5TestDatabase;

final class RenderClipLifecycleTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $clipId;
    private string $storageRoot;
    private string $renderRoot;

    protected function setUp(): void
    {
        $this->pdo = SafePhase5TestDatabase::using(
            getenv('TEST_DB_DSN'),
            static fn (string $dsn): PDO => new PDO(
                $dsn,
                getenv('TEST_DB_USERNAME') ?: null,
                getenv('TEST_DB_PASSWORD') ?: null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            )
        );
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->storageRoot = sys_get_temp_dir() . '/clip-lifecycle-storage-' . bin2hex(random_bytes(6));
        $this->renderRoot = sys_get_temp_dir() . '/clip-lifecycle-render-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->storageRoot, 0700, true));
        self::assertTrue(mkdir($this->renderRoot, 0700, true));
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->prepare(
                "DELETE FROM render_artifact_cleanups WHERE object_key LIKE ? OR object_key LIKE ?"
            )->execute([
                'processed/' . $this->projectId . '/%',
                'thumbnails/' . $this->projectId . '/%',
            ]);
            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
            $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$this->userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
        }
        foreach ([$this->storageRoot ?? '', $this->renderRoot ?? ''] as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function testCompletesPublishesSizesCleansTempsAndReplaysWithoutRenderingOrCredits(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(3);
        $renderer = new LifecycleRenderer($this->renderRoot, 'video-fixture', 'thumbnail-fixture');
        $storage = new LocalPrivateStorage($this->storageRoot, 1000);
        $handler = $this->handler($renderer, $storage, new LeaseProcessingEffectGuard($this->pdo), 100, 100);

        $outcome = $handler->handle($job);

        self::assertSame('completed', $outcome->status());
        $clip = $this->clipState();
        self::assertSame('completed', $clip['status']);
        self::assertSame(strlen('video-fixture'), (int) $clip['output_size_bytes']);
        self::assertSame(strlen('thumbnail-fixture'), (int) $clip['thumbnail_size_bytes']);
        self::assertMatchesRegularExpression('#^processed/' . $this->projectId . '/' . $this->clipId . '-[a-f0-9]{32}\.mp4$#D', (string) $clip['output_file']);
        self::assertMatchesRegularExpression('#^thumbnails/' . $this->projectId . '/' . $this->clipId . '-[a-f0-9]{32}\.jpg$#D', (string) $clip['thumbnail']);
        self::assertSame('video-fixture', file_get_contents($storage->absolutePath((string) $clip['output_file'])));
        self::assertSame('thumbnail-fixture', file_get_contents($storage->absolutePath((string) $clip['thumbnail'])));
        self::assertSame(['completed', '100'], $this->projectState());
        self::assertSame('original', $renderer->lastRequest?->reframePlan()->mode());
        self::assertSame(1920, $renderer->lastRequest?->source()->width());
        self::assertSame(1080, $renderer->lastRequest?->source()->height());
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM render_artifact_cleanups')->fetchColumn()
        );
        self::assertFileDoesNotExist($renderer->lastVideoPath);
        self::assertFileDoesNotExist($renderer->lastThumbnailPath);
        self::assertSame($ledger, $this->ledgerCount());

        self::assertSame('completed', $handler->handle($job)->status());
        self::assertSame(1, $renderer->calls);
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testTimeoutWithAttemptsRemainingReturnsQueuedForRetryWithoutCredits(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(3);
        $renderer = new LifecycleRenderer($this->renderRoot, '', '', ClipRenderException::withCode('render_timeout'));

        $outcome = $this->handler($renderer, new LocalPrivateStorage($this->storageRoot, 1000), new LeaseProcessingEffectGuard($this->pdo))->handle($job);

        self::assertSame('retry', $outcome->status());
        self::assertSame('render_timeout', $outcome->code());
        self::assertSame('queued', $this->clipState()['status']);
        self::assertSame(['rendering', '96'], $this->projectState());
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testUnavailableRendererReturnsQueuedAndDeferredWithoutConsumingAttemptCredit(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(3);
        $renderer = new LifecycleRenderer($this->renderRoot, '', '', ClipRenderException::withCode('render_unavailable'));

        $outcome = $this->handler($renderer, new LocalPrivateStorage($this->storageRoot, 1000), new LeaseProcessingEffectGuard($this->pdo))->handle($job);

        self::assertSame('deferred', $outcome->status());
        self::assertSame(300, $outcome->delaySeconds());
        self::assertSame('queued', $this->clipState()['status']);
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testExhaustedFailureMarksClipFailedAndSynchronizesProjectWithoutCredits(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(1);
        $renderer = new LifecycleRenderer($this->renderRoot, '', '', ClipRenderException::withCode('render_output_invalid'));

        $outcome = $this->handler($renderer, new LocalPrivateStorage($this->storageRoot, 1000), new LeaseProcessingEffectGuard($this->pdo))->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_output_invalid', $outcome->code());
        self::assertSame('failed', $this->clipState()['status']);
        self::assertSame('render_output_invalid', $this->clipState()['render_error_code']);
        self::assertSame(['suggestions_ready', '92'], $this->projectState());
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testStorageLimitFailureUsesStorageCodeReturnsQueuedAndCleansTemps(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(3);
        $renderer = new LifecycleRenderer($this->renderRoot, 'oversized-video', 'thumb');

        $outcome = $this->handler($renderer, new LocalPrivateStorage($this->storageRoot, 1000), new LeaseProcessingEffectGuard($this->pdo), 4, 20)->handle($job);

        self::assertSame('retry', $outcome->status());
        self::assertSame('render_storage_failed', $outcome->code());
        self::assertSame('queued', $this->clipState()['status']);
        self::assertSame([], $this->storedFiles());
        self::assertFileDoesNotExist($renderer->lastVideoPath);
        self::assertFileDoesNotExist($renderer->lastThumbnailPath);
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testLostLeaseAfterPublicationDeletesBothAndDefersWithoutCompletionOrProjectSync(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(3);
        $renderer = new LifecycleRenderer($this->renderRoot, 'video-fixture', 'thumb-fixture');
        $storage = new LocalPrivateStorage($this->storageRoot, 1000);
        $guard = new LifecycleRejectSecondGuard(new LeaseProcessingEffectGuard($this->pdo));

        $outcome = $this->handler($renderer, $storage, $guard)->handle($job);

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame('rendering', $this->clipState()['status']);
        self::assertNull($this->clipState()['output_file']);
        self::assertNull($this->clipState()['thumbnail']);
        self::assertSame(['rendering', '96'], $this->projectState());
        self::assertSame([], $this->storedFiles());
        self::assertFileDoesNotExist($renderer->lastVideoPath);
        self::assertFileDoesNotExist($renderer->lastThumbnailPath);
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testReclaimedJobResumesTheSameRenderingRevisionAndCompletesAfterLeaseLoss(): void
    {
        $ledger = $this->ledgerCount();
        $job = $this->claim(3, new ReframeSubmission(
            '9:16',
            'manual',
            '0.250000',
            '0.750000',
            ''
        ));
        $storage = new LocalPrivateStorage($this->storageRoot, 1000);
        $firstRenderer = new LifecycleRenderer($this->renderRoot, 'first-video', 'first-thumb');
        $expiringGuard = new LifecycleExpireSecondGuard(
            $this->pdo,
            new LeaseProcessingEffectGuard($this->pdo)
        );

        $first = $this->handler($firstRenderer, $storage, $expiringGuard)->handle($job);

        self::assertSame('deferred', $first->status());
        self::assertSame(15, $first->delaySeconds());
        self::assertSame('rendering', $this->clipState()['status']);
        self::assertSame([], $this->storedFiles());
        self::assertSame('manual', $firstRenderer->lastRequest?->reframePlan()->mode());
        self::assertSame('0.250000', $firstRenderer->lastRequest?->reframePlan()->keyframes()[0]->centerXDecimal());

        $reclaimed = (new ProcessingJobRepository($this->pdo))->claimNext(
            'media',
            'render-lifecycle-reclaimed',
            120
        );
        self::assertNotNull($reclaimed);
        $secondRenderer = new LifecycleRenderer($this->renderRoot, 'second-video', 'second-thumb');

        $second = $this->handler(
            $secondRenderer,
            $storage,
            new LeaseProcessingEffectGuard($this->pdo)
        )->handle($reclaimed);

        self::assertSame('completed', $second->status());
        self::assertSame(1, $secondRenderer->calls);
        self::assertSame('completed', $this->clipState()['status']);
        self::assertSame(['completed', '100'], $this->projectState());
        self::assertCount(2, $this->storedFiles());
        self::assertSame('manual', $secondRenderer->lastRequest?->reframePlan()->mode());
        self::assertSame(
            $firstRenderer->lastRequest?->reframePlan()->keyframes()[0]->centerXDecimal(),
            $secondRenderer->lastRequest?->reframePlan()->keyframes()[0]->centerXDecimal()
        );
        self::assertSame(
            $firstRenderer->lastRequest?->reframePlan()->keyframes()[0]->centerYDecimal(),
            $secondRenderer->lastRequest?->reframePlan()->keyframes()[0]->centerYDecimal()
        );
        self::assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM clip_render_profiles WHERE clip_id = ' . $this->clipId . ' AND render_revision = 1'
            )->fetchColumn()
        );
        self::assertSame($ledger, $this->ledgerCount());
    }

    /** @dataProvider matchedReframeProvider */
    public function testMatchedManualAndAutomaticProfilesReachTheRendererExactly(
        ReframeSubmission $submission,
        string $expectedMode,
        string $expectedAspect,
        array $expectedCoordinates
    ): void {
        $job = $this->claim(3, $submission);
        $renderer = new LifecycleRenderer($this->renderRoot, 'video-fixture', 'thumbnail-fixture');

        $outcome = $this->handler(
            $renderer,
            new LocalPrivateStorage($this->storageRoot, 1000),
            new LeaseProcessingEffectGuard($this->pdo)
        )->handle($job);

        self::assertSame('completed', $outcome->status());
        $plan = $renderer->lastRequest?->reframePlan();
        self::assertNotNull($plan);
        self::assertSame($expectedMode, $plan->mode());
        self::assertSame($expectedAspect, $plan->aspectRatio()->value());
        self::assertSame($expectedCoordinates, array_map(
            static fn ($point): array => [
                $point->atMs(),
                $point->centerXDecimal(),
                $point->centerYDecimal(),
                $point->source(),
            ],
            $plan->keyframes()
        ));
        self::assertSame(['clip_id', 'render_revision'], array_keys($job->payload()));
    }

    public function matchedReframeProvider(): iterable
    {
        yield 'manual' => [
            new ReframeSubmission('1:1', 'manual', '0.125000', '0.875000', ''),
            'manual',
            '1:1',
            [[0, '0.125000', '0.875000', 'manual']],
        ];
        yield 'automatic' => [
            new ReframeSubmission(
                '4:5',
                'auto',
                '',
                '',
                '[{"at_ms":0,"center_x":0.200000,"center_y":0.400000},'
                . '{"at_ms":22750,"center_x":0.800000,"center_y":0.600000}]'
            ),
            'auto',
            '4:5',
            [
                [0, '0.200000', '0.400000', 'detected'],
                [22750, '0.800000', '0.600000', 'detected'],
            ],
        ];
    }

    public function testLegacyJobWithoutAnyProfileRendersOriginal(): void
    {
        $job = $this->claim(3);
        $this->pdo->prepare('DELETE FROM clip_render_profiles WHERE clip_id = ?')->execute([$this->clipId]);
        $renderer = new LifecycleRenderer($this->renderRoot, 'legacy-video', 'legacy-thumb');

        $outcome = $this->handler(
            $renderer,
            new LocalPrivateStorage($this->storageRoot, 1000),
            new LeaseProcessingEffectGuard($this->pdo)
        )->handle($job);

        self::assertSame('completed', $outcome->status());
        self::assertSame(1, $renderer->calls);
        self::assertSame('original', $renderer->lastRequest?->reframePlan()->mode());
        self::assertSame('original', $renderer->lastRequest?->reframePlan()->aspectRatio()->value());
    }

    public function testLegacyOriginalPreservesPartialSourceGeometry(): void
    {
        $job = $this->claim(3);
        $this->pdo->prepare('DELETE FROM clip_render_profiles WHERE clip_id = ?')->execute([$this->clipId]);
        $this->pdo->prepare('UPDATE project_sources SET width = NULL, height = 1080 WHERE project_id = ?')
            ->execute([$this->projectId]);
        $renderer = new LifecycleRenderer($this->renderRoot, 'legacy-video', 'legacy-thumb');

        $outcome = $this->handler(
            $renderer,
            new LocalPrivateStorage($this->storageRoot, 1000),
            new LeaseProcessingEffectGuard($this->pdo)
        )->handle($job);

        self::assertSame('completed', $outcome->status());
        self::assertSame('original', $renderer->lastRequest?->reframePlan()->mode());
        self::assertNull($renderer->lastRequest?->source()->width());
        self::assertSame(1080, $renderer->lastRequest?->source()->height());
        self::assertFalse($renderer->lastRequest?->source()->hasUsableGeometry());
    }

    public function testPersistentRevisionMismatchFailsWithoutRendererOrRetry(): void
    {
        $ledger = $this->ledgerCount();
        $claimed = $this->claim(3, new ReframeSubmission(
            '9:16',
            'manual',
            '0.250000',
            '0.500000',
            ''
        ));
        $this->pdo->prepare('UPDATE clips SET render_revision = 2 WHERE id = ?')->execute([$this->clipId]);
        $this->pdo->prepare(
            'UPDATE processing_jobs SET payload_json = JSON_OBJECT(\'clip_id\', ?, \'render_revision\', 2) WHERE id = ?'
        )->execute([$this->clipId, $claimed->id()]);
        $job = new ClaimedJob(
            $claimed->id(),
            $claimed->queueName(),
            $claimed->type(),
            $claimed->projectId(),
            ['clip_id' => $this->clipId, 'render_revision' => 2],
            $claimed->workerId(),
            $claimed->leaseToken(),
            $claimed->attempts(),
            $claimed->maxAttempts()
        );
        $renderer = new LifecycleRenderer($this->renderRoot, 'unused', 'unused');

        $outcome = $this->handler(
            $renderer,
            new LocalPrivateStorage($this->storageRoot, 1000),
            new LeaseProcessingEffectGuard($this->pdo)
        )->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_output_invalid', $outcome->code());
        self::assertSame(0, $renderer->calls);
        self::assertSame('failed', $this->clipState()['status']);
        self::assertSame('render_output_invalid', $this->clipState()['render_error_code']);
        self::assertSame(['suggestions_ready', '92'], $this->projectState());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM clip_render_profiles WHERE clip_id = ' . $this->clipId . ' AND render_revision = 1'
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM clip_render_profiles WHERE clip_id = ' . $this->clipId . ' AND render_revision = 2'
        )->fetchColumn());
        self::assertSame(['clip_id' => $this->clipId, 'render_revision' => 2], $job->payload());
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testFailedImmediateDeletesArePersistedAndRecoveredByALaterHandlerRun(): void
    {
        $job = $this->claim(3);
        $this->pdo->prepare(
            'UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE id = ?'
        )->execute([$job->id()]);
        $localStorage = new LocalPrivateStorage($this->storageRoot, 1000);
        $storage = new LifecycleDeleteFailingStorage($localStorage);
        $repository = new RenderArtifactCleanupRepository($this->pdo);
        $cleanups = new LifecycleFailingCleanupStore($repository);
        $renderer = new LifecycleRenderer($this->renderRoot, 'orphan-video', 'orphan-thumb');

        $outcome = $this->handler(
            $renderer,
            $storage,
            new LifecycleRejectSecondGuard(new LeaseProcessingEffectGuard($this->pdo)),
            100,
            100,
            $cleanups
        )->handle($job);

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertCount(2, $storage->deleteAttempts);
        self::assertCount(2, $this->storedFiles());
        self::assertSame(
            2,
            (int) $this->pdo->query('SELECT COUNT(*) FROM render_artifact_cleanups')->fetchColumn()
        );

        $concurrentStorage = new LifecycleDeleteFailingStorage(new LocalPrivateStorage($this->storageRoot, 1000));
        $invalidReplay = new ClaimedJob(
            $job->id(),
            $job->queueName(),
            $job->type(),
            $job->projectId(),
            ['clip_id' => $this->clipId, 'render_revision' => 1, 'unexpected' => 1],
            $job->workerId(),
            $job->leaseToken(),
            $job->attempts(),
            $job->maxAttempts()
        );
        $this->handler(
            new LifecycleRenderer($this->renderRoot, 'unused', 'unused'),
            $concurrentStorage,
            new LeaseProcessingEffectGuard($this->pdo),
            100,
            100,
            new RenderArtifactCleanupRepository($this->pdo)
        )->handle($invalidReplay);

        self::assertSame([], $concurrentStorage->deleteAttempts);

        $storage->failDeletes = false;
        $this->pdo->exec(
            'UPDATE render_artifact_cleanups SET cleanup_after = UTC_TIMESTAMP() - INTERVAL 1 SECOND'
        );
        $invalidReplay = new ClaimedJob(
            $job->id(),
            $job->queueName(),
            $job->type(),
            $job->projectId(),
            ['clip_id' => $this->clipId, 'render_revision' => 1, 'unexpected' => 1],
            $job->workerId(),
            $job->leaseToken(),
            $job->attempts(),
            $job->maxAttempts()
        );

        $this->handler(
            new LifecycleRenderer($this->renderRoot, 'unused', 'unused'),
            $storage,
            new LeaseProcessingEffectGuard($this->pdo),
            100,
            100,
            new RenderArtifactCleanupRepository($this->pdo)
        )->handle($invalidReplay);

        self::assertCount(4, $storage->deleteAttempts);
        self::assertSame([], $this->storedFiles());
        self::assertSame([], (new RenderArtifactCleanupRepository($this->pdo))->pending());
    }

    public function testActiveWriteAheadReservationsAreInvisibleToConcurrentCleanup(): void
    {
        $job = $this->claim(3);
        $this->pdo->prepare(
            'UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE id = ?'
        )->execute([$job->id()]);
        $cleanups = new RenderArtifactCleanupRepository($this->pdo);
        $keys = [
            sprintf('processed/%d/%d-%s.mp4', $this->projectId, $this->clipId, str_repeat('a', 32)),
            sprintf('thumbnails/%d/%d-%s.jpg', $this->projectId, $this->clipId, str_repeat('a', 32)),
        ];

        self::assertTrue($cleanups->reserve($job, $keys));

        self::assertSame([], $cleanups->pending());
        self::assertSame(
            2,
            (int) $this->pdo->query('SELECT COUNT(*) FROM render_artifact_cleanups')->fetchColumn()
        );
    }

    public function testIdleWorkerRecoversTerminalCleanupAndOnlyStaleOwnedTemporaryArtifacts(): void
    {
        $storage = new LocalPrivateStorage($this->storageRoot, 1000);
        $key = sprintf(
            'processed/%d/%d-%s.mp4',
            $this->projectId,
            $this->clipId,
            str_repeat('d', 32)
        );
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'orphaned-render');
        rewind($stream);
        $storage->putStream($stream, $key, 1000);
        fclose($stream);

        $this->pdo->prepare(
            "INSERT INTO processing_jobs "
            . "(queue_name, type, project_id, payload_json, idempotency_key, status, attempts, max_attempts, available_at, finished_at) "
            . "VALUES (?, 'render_clip', ?, JSON_OBJECT('clip_id', ?, 'render_revision', 1), ?, 'failed', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            'render-maintenance-' . $this->projectId,
            $this->projectId,
            $this->clipId,
            hash('sha256', 'terminal-render-' . $this->projectId),
        ]);
        $jobId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO render_artifact_cleanups (object_key, job_id, cleanup_after) '
            . 'VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL 1 SECOND)'
        )->execute([$key, $jobId]);

        $stale = $this->renderRoot . '/cliplab-video-' . str_repeat('a', 32) . '.mp4';
        $fresh = $this->renderRoot . '/cliplab-thumbnail-' . str_repeat('b', 32) . '.jpg';
        $unrelated = $this->renderRoot . '/keep-' . str_repeat('c', 32) . '.mp4';
        file_put_contents($stale, 'stale');
        file_put_contents($fresh, 'fresh');
        file_put_contents($unrelated, 'keep');
        touch($stale, time() - 120);
        touch($unrelated, time() - 120);

        $worker = new QueueWorker(
            new ProcessingJobRepository($this->pdo),
            [],
            'render-maintenance-worker',
            120,
            null,
            new RenderMaintenance(
                new RenderArtifactCleanupRepository($this->pdo),
                $storage,
                $this->renderRoot,
                60
            )
        );

        $report = $worker->run('render-maintenance-' . $this->projectId, 1, 5);

        self::assertSame(0, $report->claimed);
        self::assertSame(0, $report->operationalErrors);
        self::assertFileDoesNotExist($storage->absolutePath($key));
        $cleanup = $this->pdo->prepare(
            'SELECT COUNT(*) FROM render_artifact_cleanups WHERE object_key = ?'
        );
        $cleanup->execute([$key]);
        self::assertSame(0, (int) $cleanup->fetchColumn());
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($fresh);
        self::assertFileExists($unrelated);
        $job = $this->pdo->prepare('SELECT status FROM processing_jobs WHERE id = ?');
        $job->execute([$jobId]);
        self::assertSame('failed', (string) $job->fetchColumn());
    }

    public function testNewerAnalysisInvalidatesTheQueuedClipBeforeRendererInvocation(): void
    {
        $job = $this->claim(3);
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, 'newer-analysis', 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))")
            ->execute([$this->projectId]);
        $renderer = new LifecycleRenderer($this->renderRoot, 'video', 'thumb');

        $outcome = $this->handler($renderer, new LocalPrivateStorage($this->storageRoot, 1000), new LeaseProcessingEffectGuard($this->pdo))->handle($job);

        self::assertSame('completed', $outcome->status());
        self::assertSame(0, $renderer->calls);
        self::assertSame('queued', $this->clipState()['status']);
        self::assertSame([], $this->storedFiles());
    }

    public function testSourceThatIsNoLongerReadyIsRejectedBeforeRendererInvocation(): void
    {
        $job = $this->claim(3);
        $this->pdo->prepare("UPDATE project_sources SET status = 'failed' WHERE project_id = ?")->execute([$this->projectId]);
        $renderer = new LifecycleRenderer($this->renderRoot, 'video', 'thumb');

        $outcome = $this->handler($renderer, new LocalPrivateStorage($this->storageRoot, 1000), new LeaseProcessingEffectGuard($this->pdo))->handle($job);

        self::assertSame('completed', $outcome->status());
        self::assertSame(0, $renderer->calls);
        self::assertSame('queued', $this->clipState()['status']);
        self::assertSame([], $this->storedFiles());
    }

    private function handler(
        ClipRenderer $renderer,
        PrivateStorage $storage,
        ProcessingEffectGuard $guard,
        int $videoMax = 100,
        int $thumbnailMax = 100,
        ?RenderArtifactCleanupStore $cleanups = null,
        ?ClipRenderProfileStore $profiles = null
    ): RenderClipHandler
    {
        return new RenderClipHandler(
            new ClipRepository($this->pdo),
            new ProjectRepository($this->pdo),
            $renderer,
            $storage,
            $guard,
            $videoMax,
            $thumbnailMax,
            $cleanups ?? new RenderArtifactCleanupRepository($this->pdo),
            $profiles ?? new ClipRenderProfileRepository($this->pdo)
        );
    }

    private function claim(int $maxAttempts, ?ReframeSubmission $submission = null): ClaimedJob
    {
        $service = new ClipRenderRequestService(
            $this->pdo,
            new ClipRepository($this->pdo),
            new ProjectRepository($this->pdo),
            new ClipRenderProfileRepository($this->pdo),
            new ReframePlanValidator(),
            new DatabaseJobDispatcher($this->pdo, 'media', $maxAttempts)
        );
        $receipt = $service->request(
            $this->clipId,
            $this->userId,
            '12.500',
            '35.250',
            $submission
        );
        self::assertNotNull($receipt);
        self::assertSame(
            $submission?->mode() ?? 'original',
            (new ClipRenderProfileRepository($this->pdo))->findForClipRevision(
                $this->clipId,
                $receipt->revision()
            )?->mode()
        );
        $job = (new ProcessingJobRepository($this->pdo))->claimNext('media', 'render-lifecycle-worker', 120);
        self::assertNotNull($job);
        return $job;
    }

    private function seed(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')->execute(['render-life-' . $suffix, 'Render life ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 10)')->execute(['Render lifecycle', 'render-life-' . $suffix . '@example.test', 'not-a-real-hash', $this->planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, 'credit', 10, 10, 'test_fixture', 'Render credits')")->execute([$this->userId]);
        $this->pdo->prepare("INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Render lifecycle project', 'suggestions_ready', 92)")->execute([$this->userId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes, duration_seconds, width, height, video_codec, has_audio, status) VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 240, 1920, 1080, 'h264', 1, 'ready')")->execute([$this->projectId, 'imports/' . $this->projectId . '/source.mp4']);
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, 'render-life-test', 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))")->execute([$this->projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category) VALUES (?, ?, 0, 'Render clip', 10, 40, 30, 90, 'Hook', 'Reason', 'insight')")->execute([$this->projectId, $analysisId]);
        $this->clipId = (int) $this->pdo->lastInsertId();
    }

    private function clipState(): array
    {
        $statement = $this->pdo->prepare('SELECT status, render_error_code, output_file, output_size_bytes, thumbnail, thumbnail_size_bytes FROM clips WHERE id = ?');
        $statement->execute([$this->clipId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function projectState(): array
    {
        $statement = $this->pdo->prepare('SELECT status, progress FROM projects WHERE id = ?');
        $statement->execute([$this->projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return [(string) $row['status'], (string) $row['progress']];
    }

    private function ledgerCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?');
        $statement->execute([$this->userId]);
        return (int) $statement->fetchColumn();
    }

    private function storedFiles(): array
    {
        if (!is_dir($this->storageRoot)) { return []; }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->storageRoot, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) { if ($file->isFile()) { $files[] = $file->getPathname(); } }
        return $files;
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) { return; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
}

final class LifecycleRenderer implements ClipRenderer
{
    public int $calls = 0;
    public ?RenderClipRequest $lastRequest = null;
    public string $lastVideoPath = '';
    public string $lastThumbnailPath = '';
    public function __construct(private string $directory, private string $video, private string $thumbnail, private ?\Throwable $exception = null) {}
    public function render(RenderClipRequest $request): RenderedClipArtifacts
    {
        ++$this->calls;
        $this->lastRequest = $request;
        if ($this->exception !== null) { throw $this->exception; }
        $this->lastVideoPath = $this->directory . '/video-' . $this->calls . '.mp4';
        $this->lastThumbnailPath = $this->directory . '/thumb-' . $this->calls . '.jpg';
        file_put_contents($this->lastVideoPath, $this->video);
        file_put_contents($this->lastThumbnailPath, $this->thumbnail);
        return new RenderedClipArtifacts($this->lastVideoPath, strlen($this->video), 'video/mp4', $this->lastThumbnailPath, strlen($this->thumbnail), 'image/jpeg');
    }
}

final class LifecycleRejectSecondGuard implements ProcessingEffectGuard
{
    private int $calls = 0;
    public function __construct(private ProcessingEffectGuard $inner) {}
    public function apply(ClaimedJob $job, callable $effect): bool
    {
        ++$this->calls;
        if ($this->calls === 2) { return false; }
        return $this->inner->apply($job, $effect);
    }
}

final class LifecycleExpireSecondGuard implements ProcessingEffectGuard
{
    private int $calls = 0;

    public function __construct(private PDO $pdo, private ProcessingEffectGuard $inner)
    {
    }

    public function apply(ClaimedJob $job, callable $effect): bool
    {
        ++$this->calls;
        if ($this->calls === 2) {
            $this->pdo->prepare(
                'UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?'
            )->execute([$job->id()]);
        }

        return $this->inner->apply($job, $effect);
    }
}

final class LifecycleDeleteFailingStorage implements PrivateStorage
{
    public bool $failDeletes = true;
    /** @var list<string> */
    public array $deleteAttempts = [];

    public function __construct(private PrivateStorage $inner)
    {
    }

    public function putUploaded(string $temporaryPath, string $objectKey): \App\Media\StoredObject
    {
        return $this->inner->putUploaded($temporaryPath, $objectKey);
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): \App\Media\StoredObject
    {
        return $this->inner->putStream($stream, $objectKey, $maxBytes);
    }

    public function absolutePath(string $objectKey): string
    {
        return $this->inner->absolutePath($objectKey);
    }

    public function delete(string $objectKey): void
    {
        $this->deleteAttempts[] = $objectKey;
        if ($this->failDeletes) {
            throw new \RuntimeException('Simulated durable cleanup storage failure.');
        }
        $this->inner->delete($objectKey);
    }
}

final class LifecycleFailingCleanupStore implements RenderArtifactCleanupStore
{
    public function __construct(private RenderArtifactCleanupStore $inner)
    {
    }

    /** @param list<string> $objectKeys */
    public function reserve(ClaimedJob $job, array $objectKeys): bool
    {
        return $this->inner->reserve($job, $objectKeys);
    }

    /** @param list<string> $objectKeys */
    public function markForCleanup(ClaimedJob $job, array $objectKeys): void
    {
        throw new \PDOException('Simulated cleanup transition database outage.');
    }

    /** @param list<string> $objectKeys */
    public function release(ClaimedJob $job, array $objectKeys): void
    {
        $this->inner->release($job, $objectKeys);
    }

    public function pending(int $limit = 25): array
    {
        return $this->inner->pending($limit);
    }

    public function forget(string $objectKey): void
    {
        $this->inner->forget($objectKey);
    }
}
