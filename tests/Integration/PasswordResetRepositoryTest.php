<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\Mailer;
use App\Core\Migrator;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\PasswordResetService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PasswordResetRepositoryTest extends TestCase
{
    public function testServiceStoresExpirationInUtcWhenPhpUsesAnotherTimezone(): void
    {
        $pdo = $this->connection();
        $root = dirname(__DIR__, 2);
        (new Migrator($pdo, $root . '/database/migrations'))->run();
        $pdo->exec((string) file_get_contents($root . '/database/seeds/plans.sql'));
        $email = 'password-reset-utc-' . bin2hex(random_bytes(8)) . '@example.test';
        $planId = (int) $pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits, role, status) VALUES (:name, :email, :password_hash, :plan_id, 0, :role, :status)');
        $insert->execute(['name' => 'UTC Test', 'email' => $email, 'password_hash' => 'unused', 'plan_id' => $planId, 'role' => 'user', 'status' => 'active']);
        $userId = (int) $pdo->lastInsertId();
        $previousTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('America/Sao_Paulo');
            (new PasswordResetService(new UserRepository($pdo), new PasswordResetRepository($pdo), new IntegrationSilentMailer(), 'https://example.test'))->request($email);
            $query = $pdo->prepare('SELECT expires_at FROM password_reset_tokens WHERE user_id = :user_id');
            $query->execute(['user_id' => $userId]);
            $expires = strtotime((string) $query->fetchColumn() . ' UTC');
            self::assertGreaterThanOrEqual(time() + 29 * 60, $expires);
            self::assertLessThanOrEqual(time() + 31 * 60, $expires);
        } finally {
            date_default_timezone_set($previousTimezone);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        }
    }

    public function testReplacementWaitsForTheUserLockAndLeavesTheActiveTokenUntouched(): void
    {
        $pdo = $this->connection();
        $root = dirname(__DIR__, 2);
        (new Migrator($pdo, $root . '/database/migrations'))->run();
        $pdo->exec((string) file_get_contents($root . '/database/seeds/plans.sql'));

        $email = 'password-reset-lock-' . bin2hex(random_bytes(8)) . '@example.test';
        $planId = (int) $pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $insertUser = $pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits, role, status) VALUES (:name, :email, :password_hash, :plan_id, 0, :role, :status)');
        $insertUser->execute([
            'name' => 'Lock Test',
            'email' => $email,
            'password_hash' => 'not-used',
            'plan_id' => $planId,
            'role' => 'user',
            'status' => 'active',
        ]);
        $userId = (int) $pdo->lastInsertId();
        $activeHash = hash('sha256', 'active-' . bin2hex(random_bytes(8)));
        $insertToken = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
        $insertToken->execute(['user_id' => $userId, 'token_hash' => $activeHash, 'expires_at' => '2099-01-01 00:00:00']);

        $locker = $this->connection();
        $waiting = $this->connection();

        $timedOut = false;

        try {
            $locker->beginTransaction();
            $lock = $locker->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            $waiting->exec('SET SESSION innodb_lock_wait_timeout = 1');

            $repository = new PasswordResetRepository($waiting);
            try {
                $repository->replaceForUser($userId, hash('sha256', 'new-' . bin2hex(random_bytes(8))), '2099-01-01 00:00:00');
            } catch (PDOException) {
                $timedOut = true;
            }
        } finally {
            if ($locker->inTransaction()) {
                $locker->rollBack();
            }
        }

        try {
            self::assertTrue($timedOut);

            $statement = $pdo->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = :token_hash AND used_at IS NULL');
            $statement->execute(['token_hash' => $activeHash]);
            self::assertSame(1, (int) $statement->fetchColumn());
        } finally {
            $deleteTokens = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
            $deleteTokens->execute(['user_id' => $userId]);
            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $deleteUser->execute(['id' => $userId]);
        }
    }

    private function connection(): PDO
    {
        $dsn = getenv('TEST_DB_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        return new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}

final class IntegrationSilentMailer implements Mailer
{
    public function send(string $recipient, string $subject, string $html): void
    {
    }
}
