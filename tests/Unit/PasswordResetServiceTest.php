<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Mailer;
use App\Contracts\CommunicationEmitter;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordResetService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasswordResetServiceTest extends TestCase
{
    public function testUsesDurableEmitterWhenProvided(): void
    {
        $emitter = new ResetRecordingEmitter();
        $service = new PasswordResetService($this->users, $this->tokens, null, 'https://example.test', $emitter);
        $service->request('person@example.com');
        self::assertSame('auth.password_reset', $emitter->event);
        self::assertSame('person@example.com', $emitter->recipient);
    }
    private PDO $pdo;
    private UserRepository $users;
    private PasswordResetRepository $tokens;
    private CapturingMailer $mailer;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(254), password_hash VARCHAR(255), status VARCHAR(16))');
        $this->pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec("INSERT INTO users (email, password_hash, status) VALUES ('person@example.com', 'previous-hash', 'active')");

        $this->users = new UserRepository($this->pdo);
        $this->tokens = new PasswordResetRepository($this->pdo);
        $this->mailer = new CapturingMailer();
    }

    public function testStoresOnlyTheHashOfTheEmailedToken(): void
    {
        $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');

        $service->request('person@example.com');

        $plainToken = $this->mailer->capturedToken();
        $storedHash = (string) $this->pdo->query('SELECT token_hash FROM password_reset_tokens')->fetchColumn();
        self::assertNotSame($plainToken, $storedHash);
        self::assertSame(hash('sha256', $plainToken), $storedHash);
    }

    public function testResetsThePasswordAndConsumesTheToken(): void
    {
        $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');
        $service->request('person@example.com');

        self::assertTrue($service->reset($this->mailer->capturedToken(), 'new-secure-password-123'));

        $user = $this->users->findByEmail('person@example.com');
        $usedAt = $this->pdo->query('SELECT used_at FROM password_reset_tokens')->fetchColumn();
        self::assertTrue(password_verify('new-secure-password-123', (string) $user['password_hash']));
        self::assertNotFalse($usedAt);
        self::assertNotEmpty($usedAt);
    }

    public function testRejectsAnExpiredTokenWithoutChangingThePassword(): void
    {
        $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');
        $service->request('person@example.com');
        $this->pdo->exec("UPDATE password_reset_tokens SET expires_at = '2000-01-01 00:00:00'");

        self::assertFalse($service->reset($this->mailer->capturedToken(), 'new-secure-password-123'));

        $user = $this->users->findByEmail('person@example.com');
        $usedAt = $this->pdo->query('SELECT used_at FROM password_reset_tokens')->fetchColumn();
        self::assertSame('previous-hash', $user['password_hash']);
        self::assertNull($usedAt);
    }

    public function testRejectsAnUnknownTokenWithoutChangingThePassword(): void
    {
        $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');
        $service->request('person@example.com');

        self::assertFalse($service->reset('not-a-real-token', 'new-secure-password-123'));

        $user = $this->users->findByEmail('person@example.com');
        self::assertSame('previous-hash', $user['password_hash']);
    }

    public function testRejectsAnAlreadyUsedToken(): void
    {
        $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');
        $service->request('person@example.com');
        $token = $this->mailer->capturedToken();

        self::assertTrue($service->reset($token, 'first-secure-password-123'));
        self::assertFalse($service->reset($token, 'second-secure-password-123'));

        $user = $this->users->findByEmail('person@example.com');
        self::assertTrue(password_verify('first-secure-password-123', (string) $user['password_hash']));
    }

    public function testRevokesOtherPendingTokensAfterARest(): void
    {
        $service = new PasswordResetService($this->users, $this->tokens, $this->mailer, 'https://example.test');
        $service->request('person@example.com');
        $this->pdo->exec("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (1, 'unrelated-token-hash', '2099-01-01 00:00:00')");

        self::assertTrue($service->reset($this->mailer->capturedToken(), 'new-secure-password-123'));

        $activeTokens = (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE used_at IS NULL')->fetchColumn();
        self::assertSame(0, $activeTokens);
    }
}

final class CapturingMailer implements Mailer
{
    private string $html = '';

    public function send(string $recipient, string $subject, string $html): void
    {
        $this->html = $html;
    }

    public function capturedToken(): string
    {
        preg_match('/[?&]token=([^&"<]+)/', html_entity_decode($this->html, ENT_QUOTES, 'UTF-8'), $matches);

        return rawurldecode($matches[1] ?? '');
    }
}

final class ResetRecordingEmitter implements CommunicationEmitter
{
    public string $event = ''; public ?string $recipient = null;
    public function emit(int $userId, string $event, array $variables, string $dedupeKey, ?string $recipient = null, array $channels = ['in_app', 'email'], ?\DateTimeImmutable $availableAt = null): void { $this->event = $event; $this->recipient = $recipient; }
    public function cancelByDedupePrefix(int $userId, string $prefix): void {}
}
