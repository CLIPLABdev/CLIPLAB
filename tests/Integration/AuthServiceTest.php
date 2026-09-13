<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;
    private string $email;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $root = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $root . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($root . '/database/seeds/plans.sql'));
        $this->auth = new AuthService($this->pdo, new UserRepository($this->pdo), new PlanRepository($this->pdo));
        $this->email = 'auth-' . bin2hex(random_bytes(8)) . '@example.test';
        $_SESSION = [];
    }

    public function testRegistersAnActiveFreeUserWithInitialCreditLedgerEntry(): void
    {
        $userId = $this->auth->register([
            'name' => 'Ana Silva',
            'email' => mb_strtoupper($this->email),
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]);

        $user = $this->pdo->prepare('SELECT u.email, u.password_hash, u.status, u.credits, p.slug FROM users u JOIN plans p ON p.id = u.plan_id WHERE u.id = :id');
        $user->execute(['id' => $userId]);
        $record = $user->fetch();
        $credit = $this->pdo->prepare('SELECT type, amount, balance_after, reference_type FROM credit_transactions WHERE user_id = :user_id');
        $credit->execute(['user_id' => $userId]);
        $transaction = $credit->fetch();

        self::assertSame(mb_strtolower($this->email), $record['email']);
        self::assertTrue(password_verify('secure-password-123', $record['password_hash']));
        self::assertSame('active', $record['status']);
        self::assertSame('free', $record['slug']);
        self::assertSame(10, (int) $record['credits']);
        self::assertSame('credit', $transaction['type']);
        self::assertSame(10, (int) $transaction['amount']);
        self::assertSame(10, (int) $transaction['balance_after']);
        self::assertSame('registration', $transaction['reference_type']);
    }

    public function testAuthenticatesOnlyWithTheCorrectPassword(): void
    {
        $this->auth->register([
            'name' => 'Ana Silva',
            'email' => $this->email,
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]);

        self::assertFalse($this->auth->attempt($this->email, 'wrong-password'));
        self::assertTrue($this->auth->attempt(mb_strtoupper($this->email), 'secure-password-123'));
        self::assertIsInt($_SESSION['user_id']);
    }
}
