<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\DashboardController;
use App\Controllers\ProfileController;
use App\Core\View;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UserRepository;
use App\Services\DashboardService;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreditBalancePresentationTest extends TestCase
{
    public function testDashboardSidebarCardAndProfileUseTheSameLedgerBalance(): void
    {
        $_SESSION = ['user_id' => 17];
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, name TEXT NOT NULL, monthly_minutes INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, credits INTEGER NOT NULL, plan_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, original_duration_seconds INTEGER NOT NULL DEFAULT 0, processed_duration_seconds INTEGER NOT NULL DEFAULT 0, storage_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, balance_after INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO plans (id, name, monthly_minutes) VALUES (1, 'Free', 30)");
        $pdo->exec("INSERT INTO users (id, name, email, credits, plan_id, status) VALUES (17, 'Ana', 'ana@example.test', 10, 1, 'active')");
        $pdo->exec("INSERT INTO credit_transactions (id, user_id, balance_after, created_at) VALUES (1, 17, 4, '2026-09-03 10:00:00')");

        $users = new UserRepository($pdo);
        $dashboard = new DashboardController(new View(), new DashboardService(new ProjectRepository($pdo), new CreditTransactionRepository($pdo), $users, new DateTimeZone('UTC')), $users);
        $profile = new ProfileController(new View(), $users);

        $dashboardHtml = $dashboard->index()->body();
        $profileHtml = $profile->edit()->body();

        self::assertStringContainsString('4 créditos em saldo', $dashboardHtml);
        self::assertStringContainsString('id="creditos-titulo">4 créditos', $dashboardHtml);
        self::assertStringContainsString('<dt>Créditos disponíveis</dt><dd>4</dd>', $profileHtml);
    }
}
