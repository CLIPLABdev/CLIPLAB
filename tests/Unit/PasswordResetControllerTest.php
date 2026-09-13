<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Mailer;
use App\Controllers\PasswordResetController;
use App\Core\Request;
use App\Core\View;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasswordResetControllerTest extends TestCase
{
    public function testRendersPasswordRecoveryFormsWithoutCreatingADatabaseConnection(): void
    {
        $_SESSION = [];
        $controller = new PasswordResetController(new View(), static fn () => throw new \LogicException('GET must not require the database.'));

        $forgot = $controller->showForgotPassword();
        $reset = $controller->showResetPassword(Request::fake('GET', '/redefinir-senha?token=example-token'));

        self::assertSame(200, $forgot->status());
        self::assertStringContainsString('action="/esqueci-minha-senha"', $forgot->body());
        self::assertStringContainsString('name="_token"', $forgot->body());
        self::assertSame(200, $reset->status());
        self::assertStringContainsString('action="/redefinir-senha"', $reset->body());
        self::assertStringContainsString('name="token"', $reset->body());
        self::assertStringContainsString('name="_token"', $reset->body());
    }

    public function testLimitsPasswordResetRequestsWithoutChangingThePublicResponse(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(254), password_hash VARCHAR(255), status VARCHAR(16))');
        $pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key CHAR(64) NOT NULL, action VARCHAR(64) NOT NULL, window_started_at DATETIME NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, UNIQUE(rate_key, action))');
        $pdo->exec("INSERT INTO users (email, password_hash, status) VALUES ('person@example.com', 'previous-hash', 'active')");

        $service = new PasswordResetService(new UserRepository($pdo), new PasswordResetRepository($pdo), new SilentMailer(), 'https://example.test');
        $controller = new PasswordResetController(new View(), static fn (): PasswordResetService => $service, static fn (): RateLimiter => new RateLimiter($pdo));

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $controller->requestReset(Request::fake('POST', '/esqueci-minha-senha', ['email' => 'person@example.com'], [], '203.0.113.20'));
            self::assertSame('/esqueci-minha-senha', $response->header('Location'));
        }

        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testPasswordResetLimitDoesNotSuppressTheSameEmailFromAnotherIp(): void
    {
        $pdo = $this->database();
        $service = new PasswordResetService(new UserRepository($pdo), new PasswordResetRepository($pdo), new SilentMailer(), 'https://example.test');
        $controller = new PasswordResetController(new View(), static fn (): PasswordResetService => $service, static fn (): RateLimiter => new RateLimiter($pdo));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $controller->requestReset(Request::fake('POST', '/esqueci-minha-senha', ['email' => 'person@example.com'], [], '203.0.113.20'));
        }
        $response = $controller->requestReset(Request::fake('POST', '/esqueci-minha-senha', ['email' => 'person@example.com'], [], '203.0.113.21'));
        self::assertSame('/esqueci-minha-senha', $response->header('Location'));
        self::assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testAggregateIpLimitCapsResetRequestsAcrossDifferentEmails(): void
    {
        $pdo = $this->database();
        for ($number = 0; $number < 10; $number++) {
            $pdo->exec("INSERT INTO users (email, password_hash, status) VALUES ('person{$number}@example.com', 'hash', 'active')");
        }
        $service = new PasswordResetService(new UserRepository($pdo), new PasswordResetRepository($pdo), new SilentMailer(), 'https://example.test');
        $controller = new PasswordResetController(new View(), static fn (): PasswordResetService => $service, static fn (): RateLimiter => new RateLimiter($pdo));
        for ($number = 0; $number < 11; $number++) {
            $controller->requestReset(Request::fake('POST', '/esqueci-minha-senha', ['email' => "person{$number}@example.com"], [], '203.0.113.30'));
        }
        self::assertSame(10, (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(254), password_hash VARCHAR(255), status VARCHAR(16))');
        $pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key CHAR(64) NOT NULL, action VARCHAR(64) NOT NULL, window_started_at DATETIME NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, UNIQUE(rate_key, action))');
        $pdo->exec("INSERT INTO users (email, password_hash, status) VALUES ('person@example.com', 'previous-hash', 'active')");
        return $pdo;
    }
}

final class SilentMailer implements Mailer
{
    public function send(string $recipient, string $subject, string $html): void
    {
    }
}
