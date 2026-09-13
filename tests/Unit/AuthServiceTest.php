<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, slug VARCHAR(64), credits INTEGER, is_active INTEGER)');
        $this->pdo->exec("INSERT INTO plans (id, slug, credits, is_active) VALUES (1, 'free', 10, 1)");
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120), email VARCHAR(254), password_hash VARCHAR(255), plan_id INTEGER, credits INTEGER, role VARCHAR(16), status VARCHAR(16))');
        $this->pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type VARCHAR(16), amount INTEGER, balance_after INTEGER, reference_type VARCHAR(64), reference_id INTEGER, description VARCHAR(255))');
        $this->auth = new AuthService($this->pdo, new UserRepository($this->pdo), new PlanRepository($this->pdo));
        $_SESSION = [];
    }

    public function testRegistersAFreeUserAndItsInitialCreditTransaction(): void
    {
        $userId = $this->auth->register([
            'name' => 'Ana Silva',
            'email' => 'ANA@EXAMPLE.COM',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]);

        $user = $this->pdo->query('SELECT email, password_hash, credits, status FROM users WHERE id = ' . $userId)->fetch(PDO::FETCH_ASSOC);
        $transaction = $this->pdo->query('SELECT type, amount, balance_after, reference_type FROM credit_transactions WHERE user_id = ' . $userId)->fetch(PDO::FETCH_ASSOC);

        self::assertSame('ana@example.com', $user['email']);
        self::assertTrue(password_verify('secure-password-123', $user['password_hash']));
        self::assertSame(10, (int) $user['credits']);
        self::assertSame('active', $user['status']);
        self::assertSame('credit', $transaction['type']);
        self::assertSame(10, (int) $transaction['amount']);
        self::assertSame(10, (int) $transaction['balance_after']);
        self::assertSame('registration', $transaction['reference_type']);
    }

    public function testAuthenticatesOnlyWithTheCorrectNormalizedCredentials(): void
    {
        $this->auth->register([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]);

        self::assertFalse($this->auth->attempt('ANA@example.com', 'incorrect-password'));
        self::assertTrue($this->auth->attempt(' ANA@EXAMPLE.COM ', 'secure-password-123'));
        self::assertIsInt($_SESSION['user_id']);
    }
}
