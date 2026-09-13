<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectStatusRouteIntegrationTest extends TestCase
{
    private PDO $pdo;
    private int $ownerId;
    private int $otherId;
    private int $projectId;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') { self::markTestSkipped('TEST_DB_DSN is not configured.'); }
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: ''));
        putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $this->ownerId = $this->user($planId, 'status-owner');
        $this->otherId = $this->user($planId, 'status-other');
        $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, 'Private project', 'ready', 100)")
            ->execute([$this->ownerId, hash('sha256', 'status-' . $this->ownerId)]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, width, height, duration_seconds, video_codec, audio_codec, has_audio, status) VALUES (?, 'upload', 'local', 'private/object.mp4', 'video.mp4', 'mp4', 'video/mp4', 2048, 1280, 720, 91, 'h264', 'aac', 1, 'ready')")
            ->execute([$this->projectId]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRouteEnforcesOwnershipAndReturnsTheMinimizedProjection(): void
    {
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        Session::put('user_id', $this->ownerId);
        $owned = $router->dispatch(Request::fake('GET', '/api/projects/' . $this->projectId . '/status'));
        $json = json_decode($owned->body(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $owned->status());
        self::assertSame('no-store', $owned->header('Cache-Control'));
        self::assertSame(['id', 'status', 'progress', 'stage', 'message', 'media', 'updated_at', 'analysis_status', 'suggestions_count', 'suggestions_url'], array_keys($json));
        self::assertArrayNotHasKey('object_key', $json['media']);
        self::assertNull($json['analysis_status']);
        self::assertSame(0, $json['suggestions_count']);
        self::assertNull($json['suggestions_url']);

        Session::put('user_id', $this->otherId);
        $foreign = $router->dispatch(Request::fake('GET', '/api/projects/' . $this->projectId . '/status'));
        $unknown = $router->dispatch(Request::fake('GET', '/api/projects/999999999/status'));
        self::assertSame(404, $foreign->status());
        self::assertSame($unknown->body(), $foreign->body());
    }

    private function user(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Status', $prefix . '-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);
        return (int) $this->pdo->lastInsertId();
    }
}
