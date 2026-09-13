<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MediaPipeConsentController;
use App\Core\Csrf;
use App\Core\ErrorHandler;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\UserConsentRepository;
use App\Services\MediaPipeConsentService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SafePhase5TestDatabase;
use Throwable;

final class MediaPipeConsentRoutesTest extends TestCase
{
    private PDO $pdo;
    private Router $router;
    private int $planId;
    private int $ownerId;
    private int $otherId;
    private int $projectId;
    private int $foreignProjectId;
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/clipforge-consent-routes-' . bin2hex(random_bytes(8)) . '.log';
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: ''));
        putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));
        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['consent-routes-' . $suffix, 'Consent routes ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->ownerId = $this->createUser('route-owner-' . $suffix);
        $this->otherId = $this->createUser('route-other-' . $suffix);
        $this->projectId = $this->createProject($this->ownerId, 'Owned consent project', $suffix . '-owned');
        $this->foreignProjectId = $this->createProject($this->otherId, 'Foreign consent project', $suffix . '-foreign');
        $this->createSuggestion($this->projectId);

        $_SESSION = ['user_id' => $this->ownerId];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        self::assertInstanceOf(Router::class, $router);
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testGuestWithValidCsrfIsRedirectedBeforeConsentMutation(): void
    {
        $_SESSION = [];
        $token = Csrf::token();

        $response = $this->router->dispatch(Request::fake('POST', '/privacidade/consentimentos/mediapipe', [
            '_token' => $token,
            'return_project_id' => (string) $this->projectId,
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->consentRowCount());
    }

    public function testGlobalCsrfRejectsGrantAndRevokeBeforeMutation(): void
    {
        foreach (['/privacidade/consentimentos/mediapipe', '/privacidade/consentimentos/mediapipe/revogar'] as $path) {
            $_SESSION = ['user_id' => $this->ownerId];
            Csrf::token();
            $response = $this->router->dispatch(Request::fake('POST', $path, [
                '_token' => 'invalid',
                'return_project_id' => (string) $this->projectId,
            ]));
            self::assertSame(419, $response->status());
        }

        self::assertSame(0, $this->consentRowCount());
    }

    public function testAuthenticatedGrantAndRevokeAreIdempotentAndReturnToOwnedProject(): void
    {
        $grant = $this->post('/privacidade/consentimentos/mediapipe', (string) $this->projectId);
        self::assertSame('/projetos/' . $this->projectId, $grant->header('Location'));
        self::assertTrue($this->consents()->isActive(
            $this->ownerId,
            MediaPipeConsentService::PURPOSE,
            MediaPipeConsentService::POLICY_VERSION
        ));

        $this->post('/privacidade/consentimentos/mediapipe', (string) $this->projectId);
        self::assertSame(1, $this->consentRowCount());

        $revoke = $this->post('/privacidade/consentimentos/mediapipe/revogar', (string) $this->projectId);
        self::assertSame('/projetos/' . $this->projectId, $revoke->header('Location'));
        self::assertFalse($this->consents()->isActive(
            $this->ownerId,
            MediaPipeConsentService::PURPOSE,
            MediaPipeConsentService::POLICY_VERSION
        ));

        $this->post('/privacidade/consentimentos/mediapipe/revogar', (string) $this->projectId);
        self::assertSame(1, $this->consentRowCount());
    }

    public function testReturnTargetNeverTrustsForeignMalformedOrUrlInput(): void
    {
        foreach ([
            (string) $this->foreignProjectId,
            '0',
            '01',
            '+1',
            '1e2',
            '18446744073709551615',
            'https://evil.example/projetos/1',
        ] as $returnProjectId) {
            $response = $this->post('/privacidade/consentimentos/mediapipe', $returnProjectId);
            self::assertSame('/projetos', $response->header('Location'));
            self::assertStringNotContainsString('evil.example', (string) $response->header('Location'));
        }
    }

    public function testProjectHtmlProjectsConsentStateWithoutLoadingTheSdkOrModel(): void
    {
        $before = $this->router->dispatch(Request::fake('GET', '/projetos/' . $this->projectId));
        self::assertSame(200, $before->status());
        self::assertStringContainsString('data-consent-active="0"', $before->body());
        self::assertStringNotContainsString('mediapipe-tasks-vision', $before->body());
        self::assertStringNotContainsString('reframe-worker.js', $before->body());

        $this->post('/privacidade/consentimentos/mediapipe', (string) $this->projectId);
        $after = $this->router->dispatch(Request::fake('GET', '/projetos/' . $this->projectId));
        self::assertSame(200, $after->status());
        self::assertStringContainsString('data-consent-active="1"', $after->body());
        self::assertStringNotContainsString('mediapipe-tasks-vision', $after->body());
        self::assertStringNotContainsString('reframe-worker.js', $after->body());
    }

    public function testGrantReturnsSafeErrorWithoutMutationWhenOwnedProjectLookupFails(): void
    {
        $secret = 'owner lookup grant database password';
        $controller = $this->controllerWithFailingOwnership($secret);
        $response = null;
        $caught = null;

        try {
            $response = $controller->grant(Request::fake('POST', '/privacidade/consentimentos/mediapipe', [
                'return_project_id' => (string) $this->projectId,
            ]));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertNull($this->consentSnapshot(), 'A failed owner lookup must not grant consent.');
        self::assertNull($caught, 'The owner lookup exception must not escape the controller.');
        self::assertNotNull($response, 'The lookup exception must be converted to a safe response.');
        self::assertSame(500, $response->status());
        self::assertStringContainsString('Ocorreu um erro inesperado.', $response->body());
        self::assertStringNotContainsString($secret, $response->body());
    }

    public function testRevokeReturnsSafeErrorWithoutMutationWhenOwnedProjectLookupFails(): void
    {
        $this->consentService()->grant($this->ownerId);
        $before = $this->consentSnapshot();
        self::assertNotNull($before);
        $secret = 'owner lookup revoke database password';
        $controller = $this->controllerWithFailingOwnership($secret);
        $response = null;
        $caught = null;

        try {
            $response = $controller->revoke(Request::fake('POST', '/privacidade/consentimentos/mediapipe/revogar', [
                'return_project_id' => (string) $this->projectId,
            ]));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame($before, $this->consentSnapshot(), 'A failed owner lookup must not revoke consent.');
        self::assertNull($caught, 'The owner lookup exception must not escape the controller.');
        self::assertNotNull($response, 'The lookup exception must be converted to a safe response.');
        self::assertSame(500, $response->status());
        self::assertStringContainsString('Ocorreu um erro inesperado.', $response->body());
        self::assertStringNotContainsString($secret, $response->body());
    }

    private function post(string $path, string $returnProjectId): \App\Core\Response
    {
        $_SESSION = ['user_id' => $this->ownerId];

        return $this->router->dispatch(Request::fake('POST', $path, [
            '_token' => Csrf::token(),
            'return_project_id' => $returnProjectId,
        ]));
    }

    private function consents(): UserConsentRepository
    {
        return new UserConsentRepository($this->pdo);
    }

    private function consentService(): MediaPipeConsentService
    {
        return new MediaPipeConsentService($this->consents());
    }

    private function controllerWithFailingOwnership(string $message): MediaPipeConsentController
    {
        return new MediaPipeConsentController(
            $this->consentService(),
            static function (int $projectId, int $userId) use ($message): bool {
                throw new RuntimeException($message);
            },
            new ErrorHandler(new Logger($this->logFile))
        );
    }

    /** @return null|array{granted_at:string,revoked_at:?string} */
    private function consentSnapshot(): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT granted_at, revoked_at FROM user_consents '
            . 'WHERE user_id = ? AND purpose = ? AND policy_version = ?'
        );
        $statement->execute([
            $this->ownerId,
            MediaPipeConsentService::PURPOSE,
            MediaPipeConsentService::POLICY_VERSION,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function consentRowCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_consents WHERE user_id = ? AND purpose = ? AND policy_version = ?'
        );
        $statement->execute([
            $this->ownerId,
            MediaPipeConsentService::PURPOSE,
            MediaPipeConsentService::POLICY_VERSION,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function createUser(string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Consent routes', $prefix . '@example.test', 'not-a-real-hash', $this->planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createProject(int $userId, string $name, string $key): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, ?, 'suggestions_ready', 100)"
        );
        $statement->execute([$userId, hash('sha256', $key), $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSuggestion(int $projectId): void
    {
        $this->pdo->prepare(
            "INSERT INTO project_sources
                (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes,
                 duration_seconds, width, height, video_codec, has_audio, status)
             VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 30, 1920, 1080, 'h264', 1, 'ready')"
        )->execute([$projectId, 'consent-routes/' . $projectId . '/source.mp4']);
        $this->pdo->prepare(
            "INSERT INTO ai_analyses (project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at)
             VALUES (?, 'consent-routes', 'fake-model', 'completed', 'Resumo', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        )->execute([$projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clips
                (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds,
                 viral_score, hook, reason, category, status)
             VALUES (?, ?, 0, 'Consent clip', 0, 20, 20, 90, 'Hook', 'Reason', 'other', 'suggested')"
        )->execute([$projectId, $analysisId]);
    }
}
