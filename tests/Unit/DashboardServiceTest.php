<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UserRepository;
use App\Services\DashboardService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    public function testSummaryCountsSuggestionsReadyAsAnalyzedWithoutCountingLegacyReady(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, status TEXT NOT NULL, processed_duration_seconds INTEGER NOT NULL DEFAULT 0, storage_bytes INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO projects (id, user_id, status) VALUES (1, 17, 'completed'), (2, 17, 'suggestions_ready'), (3, 17, 'ready'), (4, 18, 'suggestions_ready')");

        $summary = (new ProjectRepository($pdo))->summaryForUser(17);

        self::assertSame(3, $summary['projects']);
        self::assertSame(2, $summary['processed']);
    }

    public function testUsesOnlyTheCurrentMonthlyCycleForAvailableMinutes(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, name TEXT NOT NULL, monthly_minutes INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, credits INTEGER NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL, plan_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, original_duration_seconds INTEGER NOT NULL DEFAULT 0, processed_duration_seconds INTEGER NOT NULL DEFAULT 0, storage_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, balance_after INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO plans (id, name, monthly_minutes) VALUES (1, 'Free', 30)");
        $pdo->exec("INSERT INTO users (id, credits, name, email, plan_id, status) VALUES (17, 10, 'Ana', 'ana@example.test', 1, 'active')");
        $pdo->exec("INSERT INTO projects (id, user_id, name, status, processed_duration_seconds, created_at) VALUES (1, 17, 'Dentro', 'completed', 61, '2026-09-01 00:00:00'), (2, 17, 'Antes', 'completed', 120, '2026-08-31 23:59:59'), (3, 17, 'Próximo', 'completed', 120, '2026-10-01 00:00:00')");

        $metrics = (new DashboardService(new ProjectRepository($pdo), new CreditTransactionRepository($pdo), new UserRepository($pdo), new DateTimeZone('UTC')))->forUser(17, new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC')));

        self::assertSame(2, $metrics['minutes_used']);
        self::assertSame(28, $metrics['minutes']);
    }

    public function testUsesTheLedgerBalanceAsTheCanonicalCreditValueForTheProfile(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, name TEXT NOT NULL, monthly_minutes INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, credits INTEGER NOT NULL, plan_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, balance_after INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO plans (id, name, monthly_minutes) VALUES (1, 'Free', 30)");
        $pdo->exec("INSERT INTO users (id, name, email, credits, plan_id, status) VALUES (17, 'Ana', 'ana@example.test', 10, 1, 'active')");
        $pdo->exec("INSERT INTO credit_transactions (id, user_id, balance_after, created_at) VALUES (1, 17, 4, '2026-09-03 10:00:00')");

        $profile = (new UserRepository($pdo))->findDashboardProfile(17);

        self::assertSame(4, $profile['credits']);
    }
    public function testReturnsOnlyTheAuthenticatedUsersRealMetricsAndRecentProjects(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, name TEXT NOT NULL, monthly_minutes INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, credits INTEGER NOT NULL, plan_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, original_duration_seconds INTEGER NOT NULL DEFAULT 0, processed_duration_seconds INTEGER NOT NULL DEFAULT 0, storage_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, balance_after INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO plans (id, name, monthly_minutes) VALUES (1, 'Free', 30)");
        $pdo->exec("INSERT INTO users (id, name, email, credits, plan_id, status) VALUES (17, 'Ana', 'ana@example.test', 10, 1, 'active'), (18, 'Outra', 'outra@example.test', 10, 1, 'active')");
        $pdo->exec("INSERT INTO projects (id, user_id, name, status, original_duration_seconds, processed_duration_seconds, storage_bytes, created_at) VALUES (1, 17, 'Entrevista', 'completed', 120, 61, 1024, '2026-09-02 10:00:00'), (2, 17, 'Aula', 'draft', 60, 0, 512, '2026-09-03 10:00:00'), (3, 18, 'Privado', 'completed', 999, 999, 9999, '2026-09-03 11:00:00')");
        $pdo->exec("INSERT INTO credit_transactions (id, user_id, balance_after, created_at) VALUES (1, 17, 7, '2026-09-03 10:00:00'), (2, 18, 99, '2026-09-03 11:00:00')");

        $metrics = (new DashboardService(new ProjectRepository($pdo), new CreditTransactionRepository($pdo), new UserRepository($pdo), new DateTimeZone('UTC')))->forUser(17, new DateTimeImmutable('2026-09-03 12:00:00', new DateTimeZone('UTC')));

        self::assertSame(2, $metrics['projects']);
        self::assertSame(1, $metrics['processed']);
        self::assertSame(28, $metrics['minutes']);
        self::assertSame(2, $metrics['minutes_used']);
        self::assertSame(7, $metrics['credits']);
        self::assertSame(1536, $metrics['storage_bytes']);
        self::assertCount(2, $metrics['recent']);
        self::assertSame('Aula', $metrics['recent'][0]['name']);
        self::assertNotContains('Privado', array_column($metrics['recent'], 'name'));
    }

    public function testUsesTheUsersStoredCreditBalanceWhenNoLedgerEntryExistsYet(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, name TEXT NOT NULL, monthly_minutes INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, credits INTEGER NOT NULL, plan_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, original_duration_seconds INTEGER NOT NULL DEFAULT 0, processed_duration_seconds INTEGER NOT NULL DEFAULT 0, storage_bytes INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, balance_after INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO plans (id, name, monthly_minutes) VALUES (1, 'Free', 30)");
        $pdo->exec("INSERT INTO users (id, name, email, credits, plan_id, status) VALUES (17, 'Ana', 'ana@example.test', 12, 1, 'active')");

        $metrics = (new DashboardService(new ProjectRepository($pdo), new CreditTransactionRepository($pdo), new UserRepository($pdo), new DateTimeZone('UTC')))->forUser(17, new DateTimeImmutable('2026-09-03 12:00:00', new DateTimeZone('UTC')));

        self::assertSame(12, $metrics['credits']);
    }

    public function testUsesCanonicalPlanQuotaSnapshotWhenItIsConfigured(): void
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,user_id INTEGER,name TEXT,status TEXT,original_duration_seconds INTEGER,processed_duration_seconds INTEGER,storage_bytes INTEGER,created_at TEXT)');
        $pdo->exec("INSERT INTO projects VALUES (1,17,'Video','completed',120,120,1,'2026-09-01')");
        $quota=static function(int $user,?DateTimeImmutable $now): array {
            self::assertSame(17,$user);
            return ['minutes_used'=>8,'minutes_remaining'=>22,'credits'=>7,'storage_bytes'=>123456];
        };
        $metrics=(new DashboardService(new ProjectRepository($pdo),new CreditTransactionRepository($pdo),new UserRepository($pdo),null,$quota))->forUser(17);
        self::assertSame(22,$metrics['minutes']);
        self::assertSame(8,$metrics['minutes_used']);
        self::assertSame(7,$metrics['credits']);
        self::assertSame(123456,$metrics['storage_bytes']);
    }
}
