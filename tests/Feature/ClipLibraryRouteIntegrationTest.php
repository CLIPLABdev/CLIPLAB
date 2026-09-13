<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ClipLibraryRouteIntegrationTest extends TestCase
{
    private PDO $pdo;
    private int $ownerId;
    private int $otherId;
    private string $ownerTitle;
    private string $otherTitle;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: ''));
        putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));
        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $suffix = bin2hex(random_bytes(8));
        $this->ownerId = $this->user($planId, 'route-owner-' . $suffix);
        $this->otherId = $this->user($planId, 'route-other-' . $suffix);
        $this->ownerTitle = 'Owner library clip ' . $suffix;
        $this->otherTitle = 'Foreign library clip ' . $suffix;
        $this->completedClip($this->ownerId, 'Owner library project', $this->ownerTitle, 'owner-private-' . $suffix);
        $this->completedClip($this->otherId, 'Foreign library project', $this->otherTitle, 'foreign-private-' . $suffix);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->pdo, $this->ownerId, $this->otherId)) {
            $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAuthenticatedRouteUsesSessionOwnershipAndPrivateNoStoreResponse(): void
    {
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        Session::put('user_id', $this->ownerId);

        $owned = $router->dispatch(Request::fake(
            'GET',
            '/clips?filter=completed&page=1&user_id=' . $this->otherId
        ));

        self::assertSame(200, $owned->status());
        self::assertSame('private, no-store', $owned->header('Cache-Control'));
        self::assertStringContainsString($this->ownerTitle, $owned->body());
        self::assertStringNotContainsString($this->otherTitle, $owned->body());
        self::assertStringNotContainsString('owner-private-', $owned->body());
        self::assertStringNotContainsString('output_file', $owned->body());

        Session::put('user_id', $this->otherId);
        $foreign = $router->dispatch(Request::fake('GET', '/clips?filter=completed&page=1'));
        self::assertSame(200, $foreign->status());
        self::assertStringContainsString($this->otherTitle, $foreign->body());
        self::assertStringNotContainsString($this->ownerTitle, $foreign->body());

        Session::put('user_id', $this->ownerId);
        $adversarial = $router->dispatch(new Request('GET', '/clips', [
            'filter' => ['completed'],
            'page' => str_repeat('9', 100),
            'user_id' => $this->otherId,
        ]));
        self::assertSame(200, $adversarial->status());
        self::assertStringContainsString($this->ownerTitle, $adversarial->body());
        self::assertStringNotContainsString($this->otherTitle, $adversarial->body());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGuestIsRedirectedBeforeThePrivateLibraryIsRead(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        $response = $router->dispatch(Request::fake('GET', '/clips?filter=completed'));

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertStringNotContainsString($this->ownerTitle, $response->body());
    }

    private function user(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Library Route User', $prefix . '@example.test', 'x', $planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function completedClip(int $userId, string $projectName, string $clipTitle, string $privatePrefix): void
    {
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, status, progress)
             VALUES (?, ?, ?, 'completed', 100)"
        )->execute([$userId, hash('sha256', $privatePrefix), $projectName]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO project_sources
                (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type,
                 size_bytes, duration_seconds, status)
             VALUES (?, 'upload', 'local', ?, 'route-video.mp4', 'mp4', 'video/mp4', 1000, 30, 'ready')"
        )->execute([$projectId, 'private/sources/' . $privatePrefix . '.mp4']);
        $this->pdo->prepare(
            "INSERT INTO ai_analyses
                (project_id, prompt_version, model, status, validated_response_json, completed_at)
             VALUES (?, ?, 'library-route', 'completed', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        )->execute([$projectId, substr('route-' . bin2hex(random_bytes(6)), 0, 32)]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clips
                (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                 duration_seconds, viral_score, hook, reason, category, status, render_revision,
                 output_file, output_size_bytes, thumbnail, thumbnail_size_bytes, rendered_at)
             VALUES (?, ?, 0, ?, 0, 15, 15, 88, 'Route hook', 'Route reason', 'insight',
                     'completed', 1, ?, 100, ?, 20, UTC_TIMESTAMP())"
        )->execute([
            $projectId,
            $analysisId,
            $clipTitle,
            'processed/' . $privatePrefix . '.mp4',
            'thumbnails/' . $privatePrefix . '.jpg',
        ]);
    }
}
