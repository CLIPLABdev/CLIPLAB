<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Csrf;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Services\ClipStatusService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ClipRoutesIntegrationTest extends TestCase
{
    private PDO $pdo;
    private int $ownerId;
    private int $otherId;
    private int $projectId;
    private int $suggestedClipId;
    private int $completedClipId;
    private string $storageRoot;
    private string $sourceBytes = 'owner-private-source-preview';
    private string $videoBytes = 'owner-private-mp4';
    private string $thumbnailBytes = 'owner-private-jpeg';

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: ''));
        putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));
        $this->storageRoot = sys_get_temp_dir() . '/clip-routes-' . bin2hex(random_bytes(8));
        mkdir($this->storageRoot, 0700, true);
        putenv('MEDIA_PRIVATE_ROOT=' . $this->storageRoot);

        $this->pdo = SafePhase5TestDatabase::using(
            $dsn,
            static fn (string $safeDsn): PDO => new PDO(
                $safeDsn,
                getenv('TEST_DB_USERNAME') ?: null,
                getenv('TEST_DB_PASSWORD') ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            )
        );
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $this->ownerId = $this->user($planId, 'clip-route-owner');
        $this->otherId = $this->user($planId, 'clip-route-other');
        $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, 'Clip route project', 'suggestions_ready', 92)")
            ->execute([$this->ownerId, hash('sha256', 'clip-route-project-' . $this->ownerId)]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, width, height, duration_seconds, video_codec, audio_codec, has_audio, status) VALUES (?, 'upload', 'local', 'sources/source.mp4', 'source.mp4', 'mp4', 'video/mp4', ?, 1280, 720, 120, 'h264', 'aac', 1, 'ready')")
            ->execute([$this->projectId, strlen($this->sourceBytes)]);
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at) VALUES (?, 'routes', 'test-model', 'completed', 'Resumo', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())")
            ->execute([$this->projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();

        $this->suggestedClipId = $this->clip($analysisId, 0, 'suggested');
        $this->completedClipId = $this->clip($analysisId, 1, 'completed');
        $videoKey = 'processed/' . $this->projectId . '/' . $this->completedClipId . '.mp4';
        $thumbnailKey = 'thumbnails/' . $this->projectId . '/' . $this->completedClipId . '.jpg';
        $this->writeObject('sources/source.mp4', $this->sourceBytes);
        $this->writeObject($videoKey, $this->videoBytes);
        $this->writeObject($thumbnailKey, $this->thumbnailBytes);
        $this->pdo->prepare("UPDATE clips SET render_start_time = 30.000, render_end_time = 60.000, render_revision = 1, output_file = ?, output_size_bytes = ?, thumbnail = ?, thumbnail_size_bytes = ?, rendered_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$videoKey, strlen($this->videoBytes), $thumbnailKey, strlen($this->thumbnailBytes), $this->completedClipId]);
        $profiles = new ClipRenderProfileRepository($this->pdo);
        $profiles->create(
            $this->completedClipId,
            1,
            ReframePlan::manual(
                AspectRatio::fromString('9:16'),
                new ReframeKeyframe(0, 0.25, 0.75, 'manual')
            )
        );
        $profiles->create(
            $this->completedClipId,
            2,
            ReframePlan::automatic(
                AspectRatio::fromString('4:5'),
                ReframePlan::DETECTOR_VERSION,
                [
                    new ReframeKeyframe(0, 0.1, 0.4, 'detected'),
                    new ReframeKeyframe(30000, 0.9, 0.6, 'detected'),
                ]
            )
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->pdo, $this->ownerId, $this->otherId)) {
            $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
        }
        if (isset($this->storageRoot)) {
            $this->removeDirectory($this->storageRoot);
        }
        putenv('MEDIA_PRIVATE_ROOT');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRoutesEnforceAuthCsrfOwnershipAndPrivateResponseContracts(): void
    {
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        Session::put('user_id', $this->ownerId);
        $post = $router->dispatch(Request::fake('POST', '/clips/' . $this->suggestedClipId . '/render', [
            '_token' => Csrf::token(),
            'start_time' => '12.500',
            'end_time' => '35.250',
        ]));
        self::assertSame(302, $post->status());
        self::assertSame('/projetos/' . $this->projectId, $post->header('Location'));
        self::assertSame('queued', $this->scalar('SELECT status FROM clips WHERE id = ?', [$this->suggestedClipId]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE project_id = ? AND type = 'render_clip'", [$this->projectId]));
        $suggestions = (new ClipRepository($this->pdo))->suggestionsForOwnedProject(
            $this->projectId,
            $this->ownerId
        );
        $byId = [];
        foreach ($suggestions as $suggestion) {
            $byId[(int) $suggestion['id']] = $suggestion;
            self::assertSame([
                'id',
                'title',
                'start_time',
                'end_time',
                'render_start_time',
                'render_end_time',
                'duration_seconds',
                'viral_score',
                'hook',
                'reason',
                'category',
                'status',
                'output_aspect_ratio',
                'reframe_mode',
            ], array_keys($suggestion));
            foreach ([
                'render_revision',
                'output_width',
                'output_height',
                'detector_version',
                'keyframes',
                'profile_id',
                'render_profile_id',
            ] as $privateField) {
                self::assertArrayNotHasKey($privateField, $suggestion);
            }
        }
        self::assertSame('original', $byId[$this->suggestedClipId]['output_aspect_ratio']);
        self::assertSame('original', $byId[$this->suggestedClipId]['reframe_mode']);
        self::assertSame('9:16', $byId[$this->completedClipId]['output_aspect_ratio']);
        self::assertSame('manual', $byId[$this->completedClipId]['reframe_mode']);

        $statusResponse = $router->dispatch(Request::fake('GET', '/api/clips/' . $this->completedClipId . '/status'));
        $status = json_decode($statusResponse->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $statusResponse->status());
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
        self::assertSame('9:16', $status['output_aspect_ratio']);
        self::assertSame('manual', $status['reframe_mode']);
        self::assertSame('/clips/' . $this->completedClipId . '/download', $status['download_url']);
        foreach ([
            'error_code',
            'object_key',
            'processed/',
            'thumbnails/',
            $this->storageRoot,
            'output_width',
            'output_height',
            'detector_version',
            'keyframes',
            'profile_id',
            ReframePlan::DETECTOR_VERSION,
        ] as $private) {
            self::assertStringNotContainsString($private, $statusResponse->body());
        }

        $download = $router->dispatch(Request::fake('GET', '/clips/' . $this->completedClipId . '/download'));
        ob_start();
        $download->send();
        self::assertSame($this->videoBytes, (string) ob_get_clean());
        $thumbnail = $router->dispatch(Request::fake('GET', '/clips/' . $this->completedClipId . '/thumbnail'));
        ob_start();
        $thumbnail->send();
        self::assertSame($this->thumbnailBytes, (string) ob_get_clean());
        $preview = $router->dispatch(Request::fake(
            'GET',
            '/clips/' . $this->completedClipId . '/source-preview',
            [],
            ['Range' => 'bytes=6-12']
        ));
        ob_start();
        $preview->send();
        self::assertSame(substr($this->sourceBytes, 6, 7), (string) ob_get_clean());
        self::assertSame(206, $preview->status());
        self::assertSame('bytes 6-12/' . strlen($this->sourceBytes), $preview->header('Content-Range'));
        self::assertSame('7', $preview->header('Content-Length'));
        self::assertSame('inline; filename="source-preview"', $preview->header('Content-Disposition'));
        self::assertSame('private, no-store', $preview->header('Cache-Control'));

        Session::put('user_id', $this->otherId);
        $foreignStatus = $router->dispatch(Request::fake('GET', '/api/clips/' . $this->completedClipId . '/status'));
        $unknownStatus = $router->dispatch(Request::fake('GET', '/api/clips/999999999/status'));
        self::assertSame(404, $foreignStatus->status());
        self::assertSame($unknownStatus->body(), $foreignStatus->body());
        $foreignAsset = $router->dispatch(Request::fake('GET', '/clips/' . $this->completedClipId . '/download'));
        $unknownAsset = $router->dispatch(Request::fake('GET', '/clips/999999999/download'));
        self::assertSame(404, $foreignAsset->status());
        self::assertSame($unknownAsset->body(), $foreignAsset->body());
        $foreignPreview = $router->dispatch(Request::fake('GET', '/clips/' . $this->completedClipId . '/source-preview'));
        $unknownPreview = $router->dispatch(Request::fake('GET', '/clips/999999999/source-preview'));
        self::assertSame(404, $foreignPreview->status());
        self::assertSame($unknownPreview->body(), $foreignPreview->body());

        $_SESSION = [];
        $guestApi = $router->dispatch(Request::fake('GET', '/api/clips/' . $this->completedClipId . '/status'));
        self::assertSame(401, $guestApi->status());
        self::assertSame('{"error":"unauthenticated"}', $guestApi->body());
        $guestPreview = $router->dispatch(Request::fake('GET', '/clips/' . $this->completedClipId . '/source-preview'));
        self::assertSame(302, $guestPreview->status());
        self::assertSame('/login', $guestPreview->header('Location'));
    }

    public function testStatusAllowlistFallsBackToOriginalWithoutLeakingProfileInternals(): void
    {
        foreach ([
            ['original', 'original'],
            ['9:16', 'center'],
            ['1:1', 'manual'],
            ['16:9', 'auto'],
            ['4:5', 'center'],
        ] as [$validAspect, $validMode]) {
            $validService = new ClipStatusService(static fn (): array => [
                'status' => 'suggested',
                'output_aspect_ratio' => $validAspect,
                'reframe_mode' => $validMode,
            ]);
            $valid = $validService->forOwnedClip(71, 7);
            self::assertNotNull($valid);
            self::assertSame($validAspect, $valid['output_aspect_ratio']);
            self::assertSame($validMode, $valid['reframe_mode']);
        }

        $row = [
            'status' => 'completed',
            'render_start_time' => 1.0,
            'render_end_time' => 2.0,
            'render_error_code' => null,
            'updated_at' => '2026-09-06 12:00:00',
            'output_aspect_ratio' => 'private-ratio',
            'reframe_mode' => 'private-mode',
            'output_width' => 720,
            'detector_version' => ReframePlan::DETECTOR_VERSION,
            'keyframes' => [['private' => true]],
            'profile_id' => 999,
        ];
        $service = new ClipStatusService(static fn (): array => $row);

        $status = $service->forOwnedClip(71, 7);

        self::assertNotNull($status);
        self::assertSame('original', $status['output_aspect_ratio']);
        self::assertSame('original', $status['reframe_mode']);
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
        self::assertStringNotContainsString('private', json_encode($status, JSON_THROW_ON_ERROR));

        $row['output_aspect_ratio'] = 'original';
        $row['reframe_mode'] = 'auto';
        $inconsistentService = new ClipStatusService(static fn (): array => $row);
        $inconsistent = $inconsistentService->forOwnedClip(71, 7);
        self::assertNotNull($inconsistent);
        self::assertSame('original', $inconsistent['output_aspect_ratio']);
        self::assertSame('original', $inconsistent['reframe_mode']);
    }

    private function user(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Clip Routes', $prefix . '-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function clip(int $analysisId, int $index, string $status): int
    {
        $start = $index * 30;
        $statement = $this->pdo->prepare("INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category, status) VALUES (?, ?, ?, ?, ?, ?, 30.000, 90, 'Hook', 'Reason', 'insight', ?)");
        $statement->execute([$this->projectId, $analysisId, $index, 'Clip ' . $index, $start, $start + 30, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function writeObject(string $key, string $bytes): void
    {
        $path = $this->storageRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $key);
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, $bytes);
    }

    /** @param list<mixed> $parameters */
    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
