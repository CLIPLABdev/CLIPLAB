<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\SystemLogRepository;
use App\Services\AdminBootstrapService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class AdminBootstrapServiceTest extends TestCase
{
    public function testCreatesAdminWithStrongProvidedPasswordInitialLedgerAndAudit(): void
    {
        $pdo = AdminTestDatabase::create();
        AdminTestDatabase::seed($pdo);
        $service = new AdminBootstrapService($pdo, new SystemLogRepository($pdo));

        $id = $service->provision('OWNER@EXAMPLE.TEST', 'Owner', 'Strong-password-99', false);
        $user = $pdo->query('SELECT email, password_hash, role, status, credits FROM users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);

        self::assertSame('owner@example.test', $user['email']);
        self::assertTrue(password_verify('Strong-password-99', $user['password_hash']));
        self::assertSame('admin', $user['role']);
        self::assertSame('active', $user['status']);
        self::assertSame(10, (int) $user['credits']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transactions WHERE user_id = {$id} AND reference_type = 'registration'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM system_logs WHERE event_code = 'admin.account_created' AND target_id = {$id}")->fetchColumn());
    }

    public function testPromotionPreservesExistingPasswordAndReactivatesAccount(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $hash = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $ids['user_id'])->fetchColumn();
        $pdo->exec("UPDATE users SET status = 'suspended' WHERE id = " . $ids['user_id']);
        $service = new AdminBootstrapService($pdo, new SystemLogRepository($pdo));

        $id = $service->provision('cliente@example.test', '', null, true);
        $user = $pdo->query('SELECT password_hash, role, status FROM users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);

        self::assertSame($ids['user_id'], $id);
        self::assertSame($hash, $user['password_hash']);
        self::assertSame('admin', $user['role']);
        self::assertSame('active', $user['status']);
    }

    public function testWeakPasswordCannotCreateAccount(): void
    {
        $pdo = AdminTestDatabase::create();
        AdminTestDatabase::seed($pdo);
        $service = new AdminBootstrapService($pdo, new SystemLogRepository($pdo));

        $this->expectException(DomainException::class);
        try {
            $service->provision('new-admin@example.test', 'New admin', 'short', false);
        } finally {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'new-admin@example.test'")->fetchColumn());
        }
    }
}
