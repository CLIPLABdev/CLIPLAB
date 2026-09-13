<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Core\ErrorHandler;
use App\Core\Logger;
use App\Core\Request;
use App\Core\View;
use App\Services\RateLimiter;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthRateLimitPageTest extends TestCase
{
    private string $directory;
    private string $logFile;
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->directory = sys_get_temp_dir() . '/clipforge-rate-limit-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->directory));
        $this->logFile = $this->directory . '/app.log';
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT NOT NULL, action TEXT NOT NULL, window_started_at TEXT NOT NULL, attempts INTEGER NOT NULL, expires_at TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testLoginRateLimitUsesTheDedicated429Page(): void
    {
        $limiter = new RateLimiter($this->pdo);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            self::assertTrue($limiter->hit('login-identity', '0.0.0.0|ana@example.test', 5, 900));
        }

        $controller = $this->controller($limiter);
        $response = $controller->login(Request::fake('POST', '/login', ['email' => 'ana@example.test', 'password' => 'secret']));

        self::assertSame(429, $response->status());
        self::assertStringContainsString('Muitas tentativas', $response->body());
    }

    public function testRegistrationRateLimitUsesTheDedicated429Page(): void
    {
        $limiter = new RateLimiter($this->pdo);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            self::assertTrue($limiter->hit('register-identity', '0.0.0.0|ana@example.test', 3, 3600));
        }

        $controller = $this->controller($limiter);
        $response = $controller->register(Request::fake('POST', '/cadastro', [
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]));

        self::assertSame(429, $response->status());
        self::assertStringContainsString('Muitas tentativas', $response->body());
    }

    public function testLoginLimitKeyCombinesNormalizedEmailAndRemoteAddress(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, password_hash TEXT, status TEXT)');
        $controller = $this->realController();
        $controller->login(Request::fake('POST', '/login', ['email' => ' ANA@EXAMPLE.TEST ', 'password' => 'wrong'], ['X-Forwarded-For' => '198.51.100.200'], '203.0.113.10'));
        $keys = $this->pdo->query("SELECT rate_key FROM rate_limits WHERE action = 'login-identity'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([hash('sha256', '203.0.113.10|ana@example.test')], $keys);
    }

    public function testSameEmailFromAnotherIpHasAnIndependentIdentityLimit(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, password_hash TEXT, status TEXT)');
        $controller = $this->realController();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $controller->login(Request::fake('POST', '/login', ['email' => 'ana@example.test', 'password' => 'wrong'], [], '203.0.113.10'));
        }
        $response = $controller->login(Request::fake('POST', '/login', ['email' => 'ana@example.test', 'password' => 'wrong'], [], '203.0.113.11'));
        self::assertSame(302, $response->status());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM rate_limits WHERE action = 'login-identity'")->fetchColumn());
    }

    public function testAggregateIpLimitBlocksCredentialSprayingAcrossEmails(): void
    {
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, password_hash TEXT, status TEXT)');
        $controller = $this->realController();
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $controller->login(Request::fake('POST', '/login', ['email' => "person{$attempt}@example.test", 'password' => 'wrong'], [], '203.0.113.12'));
        }
        $response = $controller->login(Request::fake('POST', '/login', ['email' => 'next@example.test', 'password' => 'wrong'], [], '203.0.113.12'));
        self::assertSame(429, $response->status());
    }

    private function controller(RateLimiter $limiter): AuthController
    {
        return new AuthController(
            new View(),
            static fn () => throw new \LogicException('Authentication must not run after the rate limit.'),
            static fn (): RateLimiter => $limiter,
            new ErrorHandler(new Logger($this->logFile))
        );
    }

    private function realController(): AuthController
    {
        $service = new AuthService($this->pdo, new UserRepository($this->pdo), new PlanRepository($this->pdo));

        return new AuthController(new View(), static fn (): AuthService => $service, fn (): RateLimiter => new RateLimiter($this->pdo), new ErrorHandler(new Logger($this->logFile)));
    }
}
