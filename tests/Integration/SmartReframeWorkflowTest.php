<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Csrf;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use App\Media\LocalFfmpegClipRenderer;
use App\Process\ProcessRunner;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\RenderClipHandler;
use App\Repositories\ClipRenderProfileRepository;
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
use Tests\Support\SmartReframeGateMutex;
use Throwable;

final class SmartReframeWorkflowTest extends TestCase
{
    private PDO $pdo;
    private string $storageRoot = '';
    private string $renderRoot = '';
    private string $ffmpegBinary = '';
    private string $ffprobeBinary = '';
    private string $runToken = '';
    private string $sourceKey = '';
    private string $sourcePath = '';
    private int $planId = 0;
    private int $ownerId = 0;
    private int $foreignId = 0;
    private int $projectId = 0;
    private int $analysisId = 0;
    private int $clipId = 0;
    /** @var array<string, string|null> */
    private array $previousEnvironment = [];
    private ?SmartReframeGateMutex $gateMutex = null;
    private bool $cleaned = false;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->ffmpegBinary = $this->requiredBinary('TEST_FFMPEG_BIN');
        $this->ffprobeBinary = $this->requiredBinary('TEST_FFPROBE_BIN');
        $this->runToken = bin2hex(random_bytes(8));
        $this->storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-reframe-workflow-storage-' . $this->runToken;
        $this->renderRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-reframe-workflow-render-' . $this->runToken;

        try {
            $username = $this->presentEnvironment('TEST_DB_USERNAME');
            $password = $this->presentEnvironment('TEST_DB_PASSWORD');
            $gateConnection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->gateMutex = SmartReframeGateMutex::acquire($gateConnection, 60);
            self::assertTrue(mkdir($this->storageRoot, 0700, true));
            self::assertTrue(mkdir($this->renderRoot, 0700, true));
            $this->setEnvironment([
                'DB_DSN' => $dsn,
                'DB_USERNAME' => $username,
                'DB_PASSWORD' => $password,
                'MEDIA_DISK' => 'local',
                'MEDIA_PRIVATE_ROOT' => $this->storageRoot,
                'FFMPEG_BINARY' => $this->ffmpegBinary,
                'FFPROBE_BINARY' => $this->ffprobeBinary,
                'GEMINI_API_KEY' => '',
                'GEMINI_MODEL' => '',
            ]);
            $this->pdo = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $this->pdo->exec("SET time_zone = '+00:00'");
            (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
            self::assertSame('clipforge_phase5_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query(
                "SELECT COUNT(*) FROM processing_jobs WHERE queue_name = 'media' AND status IN ('queued','running','retry')"
            )->fetchColumn(), 'The dedicated media queue must be empty before this workflow starts.');
            $this->seed();
        } catch (Throwable $exception) {
            $this->cleanupFixture();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupFixture();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCompletesConsentedAutomaticReframeThroughHttpSqlAndRealFfmpeg(): void
    {
        try {
            $router = require dirname(__DIR__, 2) . '/routes/web.php';
            $ledgerBefore = $this->ledgerCount();
            $sourceBytes = file_get_contents($this->sourcePath);
            self::assertIsString($sourceBytes);

            $_SESSION = [];
            $guestPreview = $router->dispatch(Request::fake('GET', '/clips/' . $this->clipId . '/source-preview'));
            self::assertSame(302, $guestPreview->status());
            self::assertSame('/login', $guestPreview->header('Location'));

            Session::put('user_id', $this->ownerId);
            $preview = $router->dispatch(Request::fake(
                'GET',
                '/clips/' . $this->clipId . '/source-preview',
                [],
                ['Range' => 'bytes=0-31']
            ));
            self::assertSame(206, $preview->status());
            self::assertSame('bytes 0-31/' . strlen($sourceBytes), $preview->header('Content-Range'));
            self::assertSame('32', $preview->header('Content-Length'));
            self::assertSame(substr($sourceBytes, 0, 32), $this->responseBody($preview));

            Session::put('user_id', $this->foreignId);
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET',
                '/clips/' . $this->clipId . '/source-preview',
                [],
                ['Range' => 'bytes=0-31']
            ))->status());

            Session::put('user_id', $this->ownerId);
            $csrf = Csrf::token();
            $consent = $router->dispatch(Request::fake('POST', '/privacidade/consentimentos/mediapipe', [
                '_token' => $csrf,
                'return_project_id' => (string) $this->projectId,
            ]));
            self::assertSame(302, $consent->status());
            self::assertSame('/projetos/' . $this->projectId, $consent->header('Location'));
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM user_consents WHERE user_id = ? AND purpose = 'mediapipe_metrics' "
                . "AND policy_version = '2026-09-04' AND revoked_at IS NULL",
                [$this->ownerId]
            ));

            $keyframes = '[{"at_ms":0,"center_x":0.100000,"center_y":0.500000},'
                . '{"at_ms":2000,"center_x":0.900000,"center_y":0.500000}]';
            $mediaJobIdsBefore = $this->mediaJobIds();
            $request = $router->dispatch(Request::fake('POST', '/clips/' . $this->clipId . '/render', [
                '_token' => $csrf,
                'start_time' => '0.000',
                'end_time' => '2.000',
                'aspect_ratio' => '4:5',
                'reframe_mode' => 'auto',
                'focus_x' => '',
                'focus_y' => '',
                'reframe_keyframes' => $keyframes,
            ]));
            self::assertSame(302, $request->status());
            self::assertSame('/projetos/' . $this->projectId, $request->header('Location'));

            $clip = $this->row('SELECT status, render_revision FROM clips WHERE id = ?', [$this->clipId]);
            self::assertSame('queued', $clip['status']);
            self::assertSame(1, (int) $clip['render_revision']);
            $revision = (int) $clip['render_revision'];
            $newJobs = $this->newMediaJobs($mediaJobIdsBefore);
            self::assertCount(1, $newJobs, 'The render submission must create exactly one new media job.');
            $jobId = (int) $newJobs[0]['id'];
            self::assertGreaterThan(0, $jobId);
            $this->assertRenderJob($newJobs[0], $revision, 'queued');
            self::assertSame(1, $this->profileCount($revision));
            self::assertSame([
                ['sequence_index' => 0, 'at_ms' => 0, 'center_x' => '0.100000', 'center_y' => '0.500000', 'source' => 'detected'],
                ['sequence_index' => 1, 'at_ms' => 2000, 'center_x' => '0.900000', 'center_y' => '0.500000', 'source' => 'detected'],
            ], $this->storedKeyframes($revision));
            $profile = $this->row(
                'SELECT aspect_ratio, reframe_mode, output_width, output_height, detector_version '
                . 'FROM clip_render_profiles WHERE clip_id = ? AND render_revision = ?',
                [$this->clipId, $revision]
            );
            self::assertSame('4:5', $profile['aspect_ratio']);
            self::assertSame('auto', $profile['reframe_mode']);
            self::assertSame(720, (int) $profile['output_width']);
            self::assertSame(900, (int) $profile['output_height']);
            self::assertSame('tasks-vision-1.0.1/blazeface-short-f16-r1', $profile['detector_version']);
            self::assertSame($ledgerBefore, $this->ledgerCount());

            $storage = new LocalPrivateStorage($this->storageRoot, 64 * 1024 * 1024);
            $runner = new ProcessRunner([$this->ffmpegBinary, $this->ffprobeBinary], $this->renderRoot);
            $handler = new RenderClipHandler(
                new ClipRepository($this->pdo),
                new ProjectRepository($this->pdo),
                new LocalFfmpegClipRenderer(
                    $storage,
                    $runner,
                    $this->ffmpegBinary,
                    $this->renderRoot,
                    60,
                    1024 * 1024,
                    64 * 1024 * 1024,
                    8 * 1024 * 1024,
                    null,
                    null,
                    $this->ffprobeBinary
                ),
                $storage,
                new LeaseProcessingEffectGuard($this->pdo),
                64 * 1024 * 1024,
                8 * 1024 * 1024,
                new RenderArtifactCleanupRepository($this->pdo),
                new ClipRenderProfileRepository($this->pdo)
            );
            $worker = new QueueWorker(
                new ProcessingJobRepository($this->pdo),
                ['render_clip' => $handler],
                'reframe-workflow-' . $this->runToken,
                300
            );
            $report = $worker->run('media', 1, 60);
            self::assertSame(1, $report->claimed);
            self::assertSame(1, $report->completed);
            self::assertSame(0, $report->retried);
            self::assertSame(0, $report->deferred);
            self::assertSame(0, $report->failed);
            self::assertSame(0, $report->operationalErrors);

            $completed = $this->row(
                'SELECT status, output_file, output_size_bytes, thumbnail, thumbnail_size_bytes FROM clips WHERE id = ?',
                [$this->clipId]
            );
            self::assertSame('completed', $completed['status']);
            $completedJob = $this->row(
                'SELECT id, project_id, queue_name, type, status, payload_json FROM processing_jobs WHERE id = ?',
                [$jobId]
            );
            $this->assertRenderJob($completedJob, $revision, 'completed');
            self::assertCount(1, $this->newMediaJobs($mediaJobIdsBefore));
            self::assertSame(1, $this->profileCount($revision));
            self::assertSame($ledgerBefore, $this->ledgerCount());
            self::assertSame(0, $this->cleanupOutboxCountForClip());
            self::assertSame([], $this->directoryEntries($this->renderRoot));

            $videoPath = $storage->absolutePath((string) $completed['output_file']);
            $thumbnailPath = $storage->absolutePath((string) $completed['thumbnail']);
            self::assertSame((int) $completed['output_size_bytes'], filesize($videoPath));
            self::assertSame((int) $completed['thumbnail_size_bytes'], filesize($thumbnailPath));
            $videoMetadata = $this->probe($runner, $videoPath);
            self::assertSame([720, 900], $this->dimensions($videoMetadata));
            self::assertSame('h264', $this->streamCodec($videoMetadata, 'video'));
            self::assertSame('aac', $this->streamCodec($videoMetadata, 'audio'));
            self::assertEqualsWithDelta(2.0, (float) ($videoMetadata['format']['duration'] ?? 0.0), 0.2);
            $thumbnailMetadata = $this->probe($runner, $thumbnailPath);
            self::assertSame('mjpeg', $this->streamCodec($thumbnailMetadata, 'video'));

            Session::put('user_id', $this->ownerId);
            $statusResponse = $router->dispatch(Request::fake('GET', '/api/clips/' . $this->clipId . '/status'));
            self::assertSame(200, $statusResponse->status());
            $status = json_decode($statusResponse->body(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('completed', $status['status']);
            self::assertSame('4:5', $status['output_aspect_ratio']);
            self::assertSame('auto', $status['reframe_mode']);
            self::assertSame('/clips/' . $this->clipId . '/thumbnail', $status['thumbnail_url']);
            self::assertSame('/clips/' . $this->clipId . '/download', $status['download_url']);
            foreach (['detector_version', 'keyframes', 'object_key', $this->storageRoot] as $private) {
                self::assertStringNotContainsString($private, $statusResponse->body());
            }

            $thumbnail = $router->dispatch(Request::fake('GET', '/clips/' . $this->clipId . '/thumbnail'));
            self::assertSame(200, $thumbnail->status());
            self::assertSame('image/jpeg', $thumbnail->header('Content-Type'));
            self::assertSame((string) $completed['thumbnail_size_bytes'], $thumbnail->header('Content-Length'));
            self::assertSame((int) $completed['thumbnail_size_bytes'], strlen($this->responseBody($thumbnail)));
            $download = $router->dispatch(Request::fake('GET', '/clips/' . $this->clipId . '/download'));
            self::assertSame(200, $download->status());
            self::assertSame('video/mp4', $download->header('Content-Type'));
            self::assertSame((string) $completed['output_size_bytes'], $download->header('Content-Length'));
            self::assertSame((int) $completed['output_size_bytes'], strlen($this->responseBody($download)));

            Session::put('user_id', $this->foreignId);
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/api/clips/' . $this->clipId . '/status'
            ))->status());
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/thumbnail'
            ))->status());
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/download'
            ))->status());
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/source-preview'
            ))->status());

            $_SESSION = [];
            self::assertSame(401, $router->dispatch(Request::fake(
                'GET', '/api/clips/' . $this->clipId . '/status'
            ))->status());
            self::assertSame(302, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/thumbnail'
            ))->status());
            self::assertSame(302, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/download'
            ))->status());

            Session::put('user_id', $this->ownerId);
            $newerAnalysisId = $this->insertNewerAnalysis();
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/api/clips/' . $this->clipId . '/status'
            ))->status());
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/source-preview'
            ))->status());
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/thumbnail'
            ))->status());
            self::assertSame(404, $router->dispatch(Request::fake(
                'GET', '/clips/' . $this->clipId . '/download'
            ))->status());
            $this->pdo->prepare('DELETE FROM ai_analyses WHERE id = ?')->execute([$newerAnalysisId]);

            self::assertSame($ledgerBefore, $this->ledgerCount());
            self::assertSame(0, $this->cleanupOutboxCountForClip());
            self::assertSame([], $this->directoryEntries($this->renderRoot));
        } finally {
            $_SESSION = [];
            $this->cleanupFixture();
        }
    }

    private function seed(): void
    {
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['reframe-workflow-' . $this->runToken, 'Reframe workflow ' . $this->runToken]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->ownerId = $this->insertUser('owner', 10);
        $this->foreignId = $this->insertUser('foreign', 0);
        $this->pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) "
            . "VALUES (?, 'credit', 10, 10, 'test_fixture', 'Smart reframe workflow credits')"
        )->execute([$this->ownerId]);
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, status, progress) "
            . "VALUES (?, ?, 'Smart reframe workflow', 'suggestions_ready', 100)"
        )->execute([$this->ownerId, hash('sha256', 'reframe-workflow-' . $this->runToken)]);
        $this->projectId = (int) $this->pdo->lastInsertId();

        $storage = new LocalPrivateStorage($this->storageRoot, 64 * 1024 * 1024);
        $this->sourceKey = 'sources/' . $this->projectId . '/workflow-' . $this->runToken . '.mp4';
        $this->sourcePath = $storage->absolutePath($this->sourceKey);
        self::assertTrue(mkdir(dirname($this->sourcePath), 0700, true));
        $runner = new ProcessRunner([$this->ffmpegBinary, $this->ffprobeBinary], $this->renderRoot);
        $this->generateSource($runner, $this->sourcePath);
        $sourceSize = filesize($this->sourcePath);
        self::assertIsInt($sourceSize);

        $this->pdo->prepare(
            "INSERT INTO project_sources "
            . "(project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, sha256, "
            . "width, height, duration_seconds, video_codec, audio_codec, has_audio, status) "
            . "VALUES (?, 'upload', 'local', ?, 'workflow.mp4', 'mp4', 'video/mp4', ?, ?, "
            . "640, 360, 3, 'h264', 'aac', 1, 'ready')"
        )->execute([$this->projectId, $this->sourceKey, $sourceSize, hash_file('sha256', $this->sourcePath)]);
        $this->pdo->prepare(
            "INSERT INTO ai_analyses "
            . "(project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at) "
            . "VALUES (?, ?, 'offline-test', 'completed', 'Synthetic workflow source', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        )->execute([$this->projectId, 'reframe-' . $this->runToken]);
        $this->analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clips "
            . "(project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, "
            . "viral_score, hook, reason, category, status) "
            . "VALUES (?, ?, 0, 'Smart reframe clip', 0.000, 2.000, 2.000, 91, "
            . "'Synthetic hook', 'Synthetic workflow reason', 'insight', 'suggested')"
        )->execute([$this->projectId, $this->analysisId]);
        $this->clipId = (int) $this->pdo->lastInsertId();
    }

    private function insertUser(string $role, int $credits): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'Smart reframe ' . $role,
            'smart-reframe-' . $role . '-' . $this->runToken . '@example.test',
            password_hash('temporary-test-password', PASSWORD_DEFAULT),
            $this->planId,
            $credits,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function generateSource(ProcessRunner $runner, string $path): void
    {
        $fixture = realpath(dirname(__DIR__) . '/Fixtures/mediapipe-synthetic-face.png');
        self::assertNotFalse($fixture);
        $result = $runner->run([
            $this->ffmpegBinary,
            '-y', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=color=red:size=640x360:rate=30:duration=3',
            '-loop', '1', '-i', $fixture,
            '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=48000:duration=3',
            '-filter_complex', '[0:v]drawbox=x=320:y=0:w=320:h=360:color=blue:t=fill[bg];'
                . '[1:v]scale=96:96[face];[bg][face]overlay=x=40+(W-w-80)*t/3:y=(H-h)/2:shortest=1[outv]',
            '-map', '[outv]', '-map', '2:a:0', '-t', '3.000',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $path,
        ], 30, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'Synthetic source generation failed.');
    }

    /** @return list<int> */
    private function mediaJobIds(): array
    {
        return array_map(
            static fn (mixed $id): int => (int) $id,
            $this->pdo->query("SELECT id FROM processing_jobs WHERE queue_name = 'media' ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /** @param list<int> $baselineIds @return list<array<string,mixed>> */
    private function newMediaJobs(array $baselineIds): array
    {
        $known = array_fill_keys(array_map('strval', $baselineIds), true);
        $rows = $this->pdo->query(
            "SELECT id, project_id, queue_name, type, status, payload_json "
            . "FROM processing_jobs WHERE queue_name = 'media' ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => !isset($known[(string) $row['id']])
        ));
    }

    /** @param array<string,mixed> $job */
    private function assertRenderJob(array $job, int $revision, string $status): void
    {
        self::assertSame($this->projectId, (int) $job['project_id']);
        self::assertSame('media', (string) $job['queue_name']);
        self::assertSame('render_clip', (string) $job['type']);
        self::assertSame($status, (string) $job['status']);
        self::assertSame(
            ['clip_id' => $this->clipId, 'render_revision' => $revision],
            json_decode((string) $job['payload_json'], true, 16, JSON_THROW_ON_ERROR)
        );
    }

    private function profileCount(int $revision): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM clip_render_profiles WHERE clip_id = ? AND render_revision = ?',
            [$this->clipId, $revision]
        );
    }

    /** @return list<array{sequence_index:int,at_ms:int,center_x:string,center_y:string,source:string}> */
    private function storedKeyframes(int $revision): array
    {
        $statement = $this->pdo->prepare(
            'SELECT k.sequence_index, k.at_ms, k.center_x, k.center_y, k.source '
            . 'FROM clip_reframe_keyframes k INNER JOIN clip_render_profiles p ON p.id = k.render_profile_id '
            . 'WHERE p.clip_id = ? AND p.render_revision = ? ORDER BY k.sequence_index'
        );
        $statement->execute([$this->clipId, $revision]);

        return array_map(static fn (array $row): array => [
            'sequence_index' => (int) $row['sequence_index'],
            'at_ms' => (int) $row['at_ms'],
            'center_x' => (string) $row['center_x'],
            'center_y' => (string) $row['center_y'],
            'source' => (string) $row['source'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function ledgerCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?', [$this->ownerId]);
    }

    private function cleanupOutboxCountForClip(): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM render_artifact_cleanups WHERE object_key LIKE ? OR object_key LIKE ?',
            [
                'processed/' . $this->projectId . '/' . $this->clipId . '-%',
                'thumbnails/' . $this->projectId . '/' . $this->clipId . '-%',
            ]
        );
    }

    private function insertNewerAnalysis(): int
    {
        $this->pdo->prepare(
            "INSERT INTO ai_analyses "
            . "(project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at) "
            . "VALUES (?, ?, 'offline-test', 'completed', 'Newer synthetic analysis', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        )->execute([$this->projectId, 'stale-' . $this->runToken]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function probe(ProcessRunner $runner, string $path): array
    {
        $result = $runner->run([
            $this->ffprobeBinary,
            '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height',
            '-of', 'json', $path,
        ], 15, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'FFprobe inspection failed.');
        $metadata = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    /** @param array<string, mixed> $metadata @return array{int, int} */
    private function dimensions(array $metadata): array
    {
        $stream = $this->stream($metadata, 'video');

        return [(int) ($stream['width'] ?? 0), (int) ($stream['height'] ?? 0)];
    }

    /** @param array<string, mixed> $metadata */
    private function streamCodec(array $metadata, string $type): string
    {
        return (string) ($this->stream($metadata, $type)['codec_name'] ?? '');
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function stream(array $metadata, string $type): array
    {
        foreach (($metadata['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }

        return [];
    }

    private function responseBody(\App\Core\Response $response): string
    {
        ob_start();
        try {
            $response->send();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** @return list<string> */
    private function directoryEntries(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : false;
        if (!is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
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

    private function requiredBinary(string $variable): string
    {
        $configured = getenv($variable);
        if (!is_string($configured) || trim($configured) === '') {
            self::fail($variable . ' must point to the real media binary for this integration test.');
        }
        $resolved = realpath($configured);
        if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
            self::fail($variable . ' must point to an executable file.');
        }

        return $resolved;
    }

    private function presentEnvironment(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value)) {
            self::fail($name . ' must be present for this integration test.');
        }

        return $value;
    }

    /** @param array<string, string> $values */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            $previous = getenv($name);
            $this->previousEnvironment[$name] = is_string($previous) ? $previous : null;
            putenv($name . '=' . $value);
        }
    }

    private function cleanupFixture(): void
    {
        if ($this->cleaned) {
            return;
        }
        $this->cleaned = true;
        $_SESSION = [];
        try {
            try {
                if (isset($this->pdo)) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    if ($this->projectId > 0) {
                        $this->pdo->prepare(
                            'DELETE FROM render_artifact_cleanups WHERE object_key LIKE ? OR object_key LIKE ?'
                        )->execute([
                            'processed/' . $this->projectId . '/%',
                            'thumbnails/' . $this->projectId . '/%',
                        ]);
                        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
                    }
                    if ($this->ownerId > 0 || $this->foreignId > 0) {
                        $ids = array_values(array_filter([$this->ownerId, $this->foreignId], static fn (int $id): bool => $id > 0));
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $this->pdo->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')')->execute($ids);
                    }
                    if ($this->planId > 0) {
                        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
                    }
                }
            } finally {
                try {
                    $this->removeDirectory($this->storageRoot);
                    $this->removeDirectory($this->renderRoot);
                } finally {
                    foreach ($this->previousEnvironment as $name => $value) {
                        $value === null ? putenv($name) : putenv($name . '=' . $value);
                    }
                    $this->previousEnvironment = [];
                }
            }
        } finally {
            $mutex = $this->gateMutex;
            $this->gateMutex = null;
            $mutex?->release();
        }
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
