<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DailyPlanCreditService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class DailyPlanCreditServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, daily_credits INTEGER NOT NULL)');
        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, plan_id INTEGER, credits INTEGER NOT NULL, status TEXT NOT NULL, daily_credits_granted_on TEXT NULL)");
        $this->pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, amount INTEGER, balance_after INTEGER, reference_type TEXT, reference_id INTEGER, description TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('INSERT INTO plans VALUES (1, 10), (2, 0)');
        $this->pdo->exec("INSERT INTO users VALUES (1, 1, 3, 'active', NULL), (2, 2, 5, 'active', NULL), (3, 1, 0, 'suspended', NULL), (4, 1, 295, 'active', NULL)");
    }

    public function testGrantsOncePerDayAndRespectsTheAccumulationCap(): void
    {
        $service = new DailyPlanCreditService($this->pdo, new DateTimeZone('America/Sao_Paulo'));
        $day = new DateTimeImmutable('2026-09-25 10:00:00', new DateTimeZone('America/Sao_Paulo'));

        $first = $service->grantDue($day);
        self::assertSame(['granted' => 2, 'skipped' => 0, 'credits' => 15], $first);
        self::assertSame(13, $this->credits(1), 'Free user gets its 10 daily credits.');
        self::assertSame(5, $this->credits(2), 'Plans without daily credits are untouched.');
        self::assertSame(0, $this->credits(3), 'Suspended accounts receive nothing.');
        self::assertSame(300, $this->credits(4), 'The balance stops at 30 days of daily credits.');

        self::assertSame(['granted' => 0, 'skipped' => 0, 'credits' => 0], $service->grantDue($day->modify('+3 hours')));
        self::assertSame(13, $this->credits(1));

        $service->grantDue($day->modify('+1 day'));
        self::assertSame(23, $this->credits(1));
        self::assertSame(300, $this->credits(4));
        self::assertSame(23, (int) $this->pdo->query('SELECT balance_after FROM credit_transactions WHERE user_id = 1 ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    private function credits(int $userId): int
    {
        return (int) $this->pdo->query('SELECT credits FROM users WHERE id = ' . $userId)->fetchColumn();
    }
}
