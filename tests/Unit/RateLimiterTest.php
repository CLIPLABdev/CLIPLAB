<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(
            'CREATE TABLE rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rate_key CHAR(64) NOT NULL,
                action VARCHAR(64) NOT NULL,
                window_started_at DATETIME NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                UNIQUE(rate_key, action)
            )'
        );
    }

    public function testBlocksAttemptAfterLimit(): void
    {
        $limiter = new RateLimiter($this->pdo);

        self::assertTrue($limiter->hit('login', 'person@example.com', 2, 60));
        self::assertTrue($limiter->hit('login', 'person@example.com', 2, 60));
        self::assertFalse($limiter->hit('login', 'person@example.com', 2, 60));
    }

    public function testStoresOnlyHashedSubjectInThePersistentLimitRecord(): void
    {
        $limiter = new RateLimiter($this->pdo);
        $limiter->hit('login', 'person@example.com', 2, 60);

        $record = $this->pdo->query('SELECT rate_key, action, attempts FROM rate_limits')->fetch(PDO::FETCH_ASSOC);

        self::assertSame('login', $record['action']);
        self::assertSame(1, (int) $record['attempts']);
        self::assertSame(hash('sha256', 'person@example.com'), $record['rate_key']);
        self::assertNotSame('person@example.com', $record['rate_key']);
    }

    public function testExpiredRecordsAreRemovedOpportunisticallyWithAControlledBatch(): void
    {
        $this->pdo->exec("INSERT INTO rate_limits (rate_key, action, window_started_at, attempts, expires_at) VALUES ('expired', 'login-ip', '2000-01-01 00:00:00', 99, '2000-01-01 00:00:00')");
        self::assertTrue((new RateLimiter($this->pdo))->hit('login-ip', '203.0.113.10', 20, 900));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM rate_limits WHERE rate_key = 'expired'")->fetchColumn());
    }
}
