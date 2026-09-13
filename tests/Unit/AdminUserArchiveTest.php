<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\AdminRepository;
use App\Repositories\SystemLogRepository;
use App\Services\AdminService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class AdminUserArchiveTest extends TestCase
{
    public function testArchiveIsReversibleAndCannotArchiveTheActingAdministrator(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $service = new AdminService($pdo, new AdminRepository($pdo), new SystemLogRepository($pdo));

        $service->archiveUser($ids['admin_id'], $ids['user_id'], 'Solicitação do titular');
        self::assertSame('suspended', $pdo->query('SELECT status FROM users WHERE id = ' . $ids['user_id'])->fetchColumn());
        self::assertNotFalse($pdo->query('SELECT archived_at FROM users WHERE id = ' . $ids['user_id'])->fetchColumn());

        $service->restoreUser($ids['admin_id'], $ids['user_id'], 'Conta reativada');
        self::assertSame('active', $pdo->query('SELECT status FROM users WHERE id = ' . $ids['user_id'])->fetchColumn());
        self::assertNull($pdo->query('SELECT archived_at FROM users WHERE id = ' . $ids['user_id'])->fetchColumn());

        $this->expectException(\DomainException::class);
        $service->archiveUser($ids['admin_id'], $ids['admin_id'], 'Tentativa proibida');
    }
}
