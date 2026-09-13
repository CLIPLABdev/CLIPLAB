<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UserRepository;
use App\Services\DashboardService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DashboardServiceIntegrationTest extends TestCase
{
    public function testMySqlMetricsAreScopedToTheOwnerAndLedger(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        $pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root = dirname(__DIR__, 2);
        (new Migrator($pdo, $root . '/database/migrations'))->run();
        $pdo->exec((string) file_get_contents($root . '/database/seeds/plans.sql'));
        $planId = (int) $pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $email = 'dashboard-' . bin2hex(random_bytes(8)) . '@example.test';
        $otherEmail = 'dashboard-other-' . bin2hex(random_bytes(8)) . '@example.test';
        $userIds = [];

        try {
            $insertUser = $pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits, role, status) VALUES (:name, :email, :password_hash, :plan_id, :credits, :role, :status)');
            foreach ([$email, $otherEmail] as $index => $address) {
                $insertUser->execute(['name' => 'Dashboard Test', 'email' => $address, 'password_hash' => 'not-used', 'plan_id' => $planId, 'credits' => 0, 'role' => 'user', 'status' => 'active']);
                $userIds[$index] = (int) $pdo->lastInsertId();
            }
            $insertProject = $pdo->prepare('INSERT INTO projects (user_id, name, status, original_duration_seconds, processed_duration_seconds, storage_bytes) VALUES (:user_id, :name, :status, :original_duration_seconds, :processed_duration_seconds, :storage_bytes)');
            $insertProject->execute(['user_id' => $userIds[0], 'name' => 'Meu projeto', 'status' => 'completed', 'original_duration_seconds' => 61, 'processed_duration_seconds' => 61, 'storage_bytes' => 2048]);
            $insertProject->execute(['user_id' => $userIds[1], 'name' => 'Projeto alheio', 'status' => 'completed', 'original_duration_seconds' => 600, 'processed_duration_seconds' => 600, 'storage_bytes' => 9999]);
            $insertCredit = $pdo->prepare('INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (:user_id, :type, :amount, :balance_after, :reference_type, :description)');
            $insertCredit->execute(['user_id' => $userIds[0], 'type' => 'credit', 'amount' => 4, 'balance_after' => 4, 'reference_type' => 'test', 'description' => 'Dashboard test']);
            $insertCredit->execute(['user_id' => $userIds[1], 'type' => 'credit', 'amount' => 90, 'balance_after' => 90, 'reference_type' => 'test', 'description' => 'Other dashboard test']);

            $metrics = (new DashboardService(new ProjectRepository($pdo), new CreditTransactionRepository($pdo), new UserRepository($pdo), new \DateTimeZone('UTC')))->forUser($userIds[0], new \DateTimeImmutable('2026-09-03 12:00:00', new \DateTimeZone('UTC')));

            self::assertSame(1, $metrics['projects']);
            self::assertSame(1, $metrics['processed']);
            self::assertSame(28, $metrics['minutes']);
            self::assertSame(2, $metrics['minutes_used']);
            self::assertSame(4, $metrics['credits']);
            self::assertSame(2048, $metrics['storage_bytes']);
            self::assertSame('Meu projeto', $metrics['recent'][0]['name']);
        } finally {
            foreach ($userIds as $userId) {
                $pdo->prepare('DELETE FROM credit_transactions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
                $pdo->prepare('DELETE FROM projects WHERE user_id = :user_id')->execute(['user_id' => $userId]);
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
            }
        }
    }
}
