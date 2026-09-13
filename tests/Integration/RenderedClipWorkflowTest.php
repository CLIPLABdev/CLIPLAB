<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\ClipRenderer;
use App\Core\Csrf;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use App\Media\RenderClipRequest;
use App\Media\RenderedClipArtifacts;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\RenderClipHandler;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipLibraryRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\RenderArtifactCleanupRepository;
use App\Services\QueueWorker;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\SafePhase5TestDatabase;

final class RenderedClipWorkflowTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $ownerId;
    private int $foreignId;
    private int $projectId;
    private int $clipId;
    private string $storageRoot;
    private string $renderRoot;
    private string $videoBytes = "\x00\x00\x00\x18ftypmp42workflow-video";
    private string $thumbnailBytes = "\xff\xd8\xff\xe0workflow-thumbnail\xff\xd9";

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: ''));
        putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));

        $this->storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clip-workflow-storage-' . bin2hex(random_bytes(8));
        $this->renderRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clip-workflow-render-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->storageRoot, 0700, true));
        self::assertTrue(mkdir($this->renderRoot, 0700, true));
        putenv('MEDIA_PRIVATE_ROOT=' . $this->storageRoot);

        $this->pdo = SafePhase5TestDatabase::using(
            $dsn,
            static fn (string $safeDsn): PDO => new PDO(
                $safeDsn,
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
        $this->seed();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (isset($this->projectId)) {
                $this->pdo->prepare(
                    'DELETE FROM render_artifact_cleanups WHERE object_key LIKE ? OR object_key LIKE ?'
                )->execute([
                    'processed/' . $this->projectId . '/%',
                    'thumbnails/' . $this->projectId . '/%',
                ]);
                $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
            }
            if (isset($this->ownerId, $this->foreignId)) {
                $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id IN (?, ?)')
                    ->execute([$this->ownerId, $this->foreignId]);
                $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')
                    ->execute([$this->ownerId, $this->foreignId]);
            }
            if (isset($this->planId)) {
                $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
            }
        }
        $this->removeDirectory($this->storageRoot ?? '');
        $this->removeDirectory($this->renderRoot ?? '');
        putenv('MEDIA_PRIVATE_ROOT');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testOwnerCompletesAndDownloadsOneRequestedRenderWhileForeignUserGetsNotFound(): void
    {
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        $ledgerBefore = $this->ledgerCount();

        Session::put('user_id', $this->ownerId);
        $request = $router->dispatch(Request::fake('POST', '/clips/' . $this->clipId . '/render', [
            '_token' => Csrf::token(),
            'start_time' => '12.500',
            'end_time' => '35.250',
        ]));

        self::assertSame(302, $request->status());
        self::assertSame('/projetos/' . $this->projectId, $request->header('Location'));
        self::assertSame(1, $this->renderJobCount());
        self::assertSame('queued', $this->scalar('SELECT status FROM clips WHERE id = ?', [$this->clipId]));

        $renderer = new WorkflowArtifactRenderer($this->renderRoot, $this->videoBytes, $this->thumbnailBytes);
        $jobs = new ProcessingJobRepository($this->pdo);
        $storage = new LocalPrivateStorage($this->storageRoot, 1024 * 1024);
        $handler = new RenderClipHandler(
            new ClipRepository($this->pdo),
            new ProjectRepository($this->pdo),
            $renderer,
            $storage,
            new LeaseProcessingEffectGuard($this->pdo),
            1024 * 1024,
            1024 * 1024,
            new RenderArtifactCleanupRepository($this->pdo),
            new ClipRenderProfileRepository($this->pdo)
        );
        $worker = new QueueWorker(
            $jobs,
            ['render_clip' => $handler],
            'render-workflow-' . bin2hex(random_bytes(6)),
            240
        );

        $report = $worker->run('media', 1, 30);

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->completed);
        self::assertSame(0, $report->retried);
        self::assertSame(0, $report->deferred);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $report->operationalErrors);
        self::assertSame(1, $renderer->calls);
        self::assertSame(12.5, $renderer->startTime);
        self::assertSame(22.75, $renderer->durationSeconds);
        self::assertSame('original', $renderer->lastRequest?->reframePlan()->mode());
        self::assertSame(1280, $renderer->lastRequest?->source()->width());
        self::assertSame(720, $renderer->lastRequest?->source()->height());
        self::assertFileDoesNotExist($renderer->videoPath);
        self::assertFileDoesNotExist($renderer->thumbnailPath);

        $clip = $this->row(
            'SELECT status, render_revision, output_file, output_size_bytes, thumbnail, thumbnail_size_bytes '
            . 'FROM clips WHERE id = ?',
            [$this->clipId]
        );
        self::assertSame('completed', $clip['status']);
        self::assertSame(1, (int) $clip['render_revision']);
        self::assertGreaterThan(0, (int) $clip['output_size_bytes']);
        self::assertGreaterThan(0, (int) $clip['thumbnail_size_bytes']);
        self::assertSame(strlen($this->videoBytes), (int) $clip['output_size_bytes']);
        self::assertSame(strlen($this->thumbnailBytes), (int) $clip['thumbnail_size_bytes']);
        self::assertSame($this->videoBytes, file_get_contents($storage->absolutePath((string) $clip['output_file'])));
        self::assertSame($this->thumbnailBytes, file_get_contents($storage->absolutePath((string) $clip['thumbnail'])));
        self::assertSame('completed', $this->scalar(
            "SELECT status FROM processing_jobs WHERE project_id = ? AND type = 'render_clip'",
            [$this->projectId]
        ));
        self::assertSame('completed', $this->scalar('SELECT status FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame(1, $this->renderJobCount());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM render_artifact_cleanups')->fetchColumn());
        self::assertSame($ledgerBefore, $this->ledgerCount());

        $library = (new ClipLibraryRepository($this->pdo))->paginateForUser($this->ownerId, 'completed', 1);
        self::assertSame([$this->clipId], array_column($library['items'], 'id'));
        self::assertSame(22.75, $library['items'][0]['display_duration_seconds']);
        self::assertTrue($library['items'][0]['has_download']);
        self::assertTrue($library['items'][0]['has_thumbnail']);
        self::assertSame('original', $library['items'][0]['output_aspect_ratio']);
        self::assertSame('original', $library['items'][0]['reframe_mode']);
        $libraryResponse = $router->dispatch(Request::fake('GET', '/clips?filter=completed'));
        self::assertSame(200, $libraryResponse->status());
        self::assertSame('private, no-store', $libraryResponse->header('Cache-Control'));
        self::assertStringContainsString('data-clip-card="' . $this->clipId . '"', $libraryResponse->body());
        self::assertStringContainsString('/clips/' . $this->clipId . '/download', $libraryResponse->body());
        self::assertStringNotContainsString((string) $clip['output_file'], $libraryResponse->body());
        self::assertStringNotContainsString((string) $clip['thumbnail'], $libraryResponse->body());
        self::assertSame(1, $this->renderJobCount());
        self::assertSame($ledgerBefore, $this->ledgerCount());

        $statusResponse = $router->dispatch(Request::fake('GET', '/api/clips/' . $this->clipId . '/status'));
        self::assertSame(200, $statusResponse->status());
        $status = json_decode($statusResponse->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'id',
            'status',
            'stage',
            'message',
            'render_start_time',
            'render_end_time',
            'output_aspect_ratio',
            'reframe_mode',
            'thumbnail_url',
            'download_url',
            'updated_at',
        ], array_keys($status));
        self::assertSame('completed', $status['status']);
        self::assertSame('original', $status['output_aspect_ratio']);
        self::assertSame('original', $status['reframe_mode']);
        self::assertSame('/clips/' . $this->clipId . '/thumbnail', $status['thumbnail_url']);
        self::assertSame('/clips/' . $this->clipId . '/download', $status['download_url']);
        foreach ([
            'output_file',
            'object_key',
            'processed/',
            'thumbnails/',
            $this->storageRoot,
            'output_width',
            'output_height',
            'detector_version',
            'keyframes',
            'render_profile_id',
        ] as $private) {
            self::assertStringNotContainsString($private, $statusResponse->body());
        }

        $download = $router->dispatch(Request::fake('GET', '/clips/' . $this->clipId . '/download'));
        self::assertSame(200, $download->status());
        self::assertSame('video/mp4', $download->header('Content-Type'));
        self::assertSame((string) strlen($this->videoBytes), $download->header('Content-Length'));
        ob_start();
        $download->send();
        self::assertSame($this->videoBytes, (string) ob_get_clean());

        $thumbnail = $router->dispatch(Request::fake('GET', '/clips/' . $this->clipId . '/thumbnail'));
        self::assertSame(200, $thumbnail->status());
        self::assertSame('image/jpeg', $thumbnail->header('Content-Type'));
        self::assertSame((string) strlen($this->thumbnailBytes), $thumbnail->header('Content-Length'));
        ob_start();
        $thumbnail->send();
        self::assertSame($this->thumbnailBytes, (string) ob_get_clean());

        Session::put('user_id', $this->foreignId);
        self::assertSame([], (new ClipLibraryRepository($this->pdo))
            ->paginateForUser($this->foreignId, 'recent', 1)['items']);
        $foreignLibrary = $router->dispatch(Request::fake('GET', '/clips'));
        self::assertSame(200, $foreignLibrary->status());
        self::assertStringNotContainsString('data-clip-card="' . $this->clipId . '"', $foreignLibrary->body());
        self::assertSame(404, $router->dispatch(
            Request::fake('GET', '/api/clips/' . $this->clipId . '/status')
        )->status());
        self::assertSame(404, $router->dispatch(
            Request::fake('GET', '/clips/' . $this->clipId . '/thumbnail')
        )->status());
        self::assertSame(404, $router->dispatch(
            Request::fake('GET', '/clips/' . $this->clipId . '/download')
        )->status());
        self::assertSame($ledgerBefore, $this->ledgerCount());
    }

    private function seed(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['render-workflow-' . $suffix, 'Render workflow ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->ownerId = $this->user('workflow-owner-' . $suffix, 10);
        $this->foreignId = $this->user('workflow-foreign-' . $suffix, 0);
        $this->pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) "
            . "VALUES (?, 'credit', 10, 10, 'test_fixture', 'Workflow credits')"
        )->execute([$this->ownerId]);
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, status, progress) "
            . "VALUES (?, ?, 'Rendered workflow project', 'suggestions_ready', 92)"
        )->execute([$this->ownerId, hash('sha256', 'render-workflow-' . $suffix)]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO project_sources "
            . "(project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, "
            . "width, height, duration_seconds, video_codec, audio_codec, has_audio, status) "
            . "VALUES (?, 'upload', 'local', ?, 'workflow-source.mp4', 'mp4', 'video/mp4', 2048, "
            . "1280, 720, 120, 'h264', 'aac', 1, 'ready')"
        )->execute([$this->projectId, 'sources/' . $this->projectId . '/source.mp4']);
        $this->pdo->prepare(
            "INSERT INTO ai_analyses "
            . "(project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at) "
            . "VALUES (?, 'render-workflow', 'test-model', 'completed', 'Workflow summary', "
            . "JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        )->execute([$this->projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clips "
            . "(project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, "
            . "viral_score, hook, reason, category, status) "
            . "VALUES (?, ?, 0, 'Workflow clip', 10.000, 40.000, 30.000, 90, "
            . "'Workflow hook', 'Workflow reason', 'insight', 'suggested')"
        )->execute([$this->projectId, $analysisId]);
        $this->clipId = (int) $this->pdo->lastInsertId();
    }

    private function user(string $prefix, int $credits): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'Rendered workflow',
            $prefix . '@example.test',
            'not-a-real-hash',
            $this->planId,
            $credits,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function ledgerCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?', [$this->ownerId]);
    }

    private function renderJobCount(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM processing_jobs WHERE project_id = ? AND type = 'render_clip'",
            [$this->projectId]
        );
    }

    /** @param list<mixed> $parameters */
    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    /** @param list<mixed> $parameters @return array<string, mixed> */
    private function row(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}

final class WorkflowArtifactRenderer implements ClipRenderer
{
    public int $calls = 0;
    public ?RenderClipRequest $lastRequest = null;
    public ?float $startTime = null;
    public ?float $durationSeconds = null;
    public string $videoPath = '';
    public string $thumbnailPath = '';

    public function __construct(
        private string $directory,
        private string $videoBytes,
        private string $thumbnailBytes
    ) {
    }

    public function render(RenderClipRequest $request): RenderedClipArtifacts
    {
        ++$this->calls;
        $this->lastRequest = $request;
        $this->startTime = $request->startTime();
        $this->durationSeconds = $request->durationSeconds();
        $nonce = bin2hex(random_bytes(8));
        $this->videoPath = $this->directory . DIRECTORY_SEPARATOR . 'workflow-' . $nonce . '.mp4';
        $this->thumbnailPath = $this->directory . DIRECTORY_SEPARATOR . 'workflow-' . $nonce . '.jpg';
        $videoSize = file_put_contents($this->videoPath, $this->videoBytes);
        $thumbnailSize = file_put_contents($this->thumbnailPath, $this->thumbnailBytes);
        if (!is_int($videoSize) || !is_int($thumbnailSize)) {
            throw new \RuntimeException('Workflow renderer fixture could not be written.');
        }

        return new RenderedClipArtifacts(
            $this->videoPath,
            $videoSize,
            'video/mp4',
            $this->thumbnailPath,
            $thumbnailSize,
            'image/jpeg'
        );
    }
}
