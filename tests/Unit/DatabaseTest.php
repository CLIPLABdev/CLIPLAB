<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCreatesPdoConnectionFromConfiguredDsn(): void
    {
        putenv('DB_DSN=sqlite::memory:');

        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE database_connection_test (id INTEGER PRIMARY KEY)');

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame('1', $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'database_connection_test'")->fetchColumn());
    }
}
