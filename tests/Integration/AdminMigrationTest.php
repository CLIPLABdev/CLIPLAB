<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;
use Throwable;

final class AdminMigrationTest extends TestCase
{
    private const LOGS = '202609060020_create_system_logs.sql';
    private const GEMINI = '202609060021_create_gemini_settings.sql';
    private PDO $pdo;
    private string $directory;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->directory = sys_get_temp_dir() . '/admin-migrations-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        foreach ([self::LOGS, self::GEMINI] as $name) {
            self::assertTrue(copy(dirname(__DIR__, 2) . '/database/migrations/' . $name, $this->directory . '/' . $name));
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(($this->directory ?? '') . '/*') ?: [] as $file) {
            @unlink($file);
        }
        if (isset($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function testCreatesExactTablesAndSecondRunIsEmpty(): void
    {
        $this->resetAdminTables();
        $migrator = new Migrator($this->pdo, $this->directory);
        self::assertSame([self::LOGS, self::GEMINI], $migrator->run());
        self::assertSame([], $migrator->run());

        self::assertSame(['bigint', 'bigint unsigned', 'NO'], $this->column('system_logs', 'id'));
        self::assertSame(['enum', "enum('info','warning','error')", 'NO'], $this->column('system_logs', 'level'));
        $contextColumn = $this->column('system_logs', 'context_json');
        self::assertContains($contextColumn[0], ['json', 'longtext']);
        self::assertSame('longtext', $contextColumn[1]);
        self::assertSame('NO', $contextColumn[2]);
        self::assertSame(['text', 'text', 'YES'], $this->column('gemini_settings', 'api_key_ciphertext'));
        self::assertSame(['varchar', 'varchar(128)', 'YES'], $this->column('gemini_settings', 'model'));
        self::assertSame(1, $this->indexCount('system_logs', 'idx_system_logs_event_created'));
        self::assertSame(1, $this->indexCount('gemini_settings', 'idx_gemini_settings_updated_by'));
    }

    public function testMalformedSurvivingTableFailsBeforeLedgerInsert(): void
    {
        $this->resetAdminTables();
        $this->pdo->exec('CREATE TABLE system_logs (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');

        try {
            (new Migrator($this->pdo, $this->directory))->run();
            self::fail('A malformed surviving admin table was accepted.');
        } catch (Throwable) {
            self::assertSame(0, $this->ledgerCount(self::LOGS));
            self::assertSame(0, $this->ledgerCount(self::GEMINI));
        } finally {
            $this->resetAdminTables();
            (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        }
    }

    private function resetAdminTables(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS gemini_settings');
        $this->pdo->exec('DROP TABLE IF EXISTS system_logs');
        $delete = $this->pdo->prepare('DELETE FROM migrations WHERE migration IN (?, ?)');
        $delete->execute([self::LOGS, self::GEMINI]);
    }

    /** @return array{string,string,string} */
    private function column(string $table, string $column): array
    {
        $statement = $this->pdo->prepare('SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute([$table, $column]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $type = (string) preg_replace('/^(bigint|int|smallint|tinyint)\(\d+\)( unsigned)?$/', '$1$2', (string) $row['COLUMN_TYPE']);

        return [(string) $row['DATA_TYPE'], $type, (string) $row['IS_NULLABLE']];
    }

    private function indexCount(string $table, string $index): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $statement->execute([$table, $index]);
        return (int) $statement->fetchColumn();
    }

    private function ledgerCount(string $migration): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
        $statement->execute([$migration]);
        return (int) $statement->fetchColumn();
    }
}
