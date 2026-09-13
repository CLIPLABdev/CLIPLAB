<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReframeKeyframeMigrationTest extends TestCase
{
    private const MIGRATION = '202609040005_create_clip_reframe_keyframes.sql';

    private PDO $pdo;
    private string $directory;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $dsn = $this->safeTestDsn();
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->assertSupportedDatabase();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->directory = sys_get_temp_dir() . '/reframe-keyframe-migration-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
        $source = dirname(__DIR__, 2) . '/database/migrations/' . self::MIGRATION;
        self::assertFileExists($source);
        self::assertTrue(copy($source, $this->directory . '/' . self::MIGRATION));
        $this->migrator = new Migrator($this->pdo, $this->directory);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    public function testCreatesExactKeyframeSchemaAndRecoversADeletedLedgerEntry(): void
    {
        $this->deleteLedger();
        self::assertContains(self::MIGRATION, $this->migrator->run());
        $this->deleteLedger();
        self::assertContains(self::MIGRATION, $this->migrator->run());
        self::assertSame([], $this->migrator->run());

        self::assertSame(['bigint', 'bigint unsigned', 'NO', null, null], $this->column('id'));
        self::assertSame(['bigint', 'bigint unsigned', 'NO', null, null], $this->column('render_profile_id'));
        self::assertSame(['tinyint', 'tinyint unsigned', 'NO', null, null], $this->column('sequence_index'));
        self::assertSame(['int', 'int unsigned', 'NO', null, null], $this->column('at_ms'));
        self::assertSame(['decimal', 'decimal(7,6) unsigned', 'NO', null, 6], $this->column('center_x'));
        self::assertSame(['decimal', 'decimal(7,6) unsigned', 'NO', null, 6], $this->column('center_y'));
        self::assertSame(["enum", "enum('manual','detected')", 'NO', null, null], $this->column('source'));
        self::assertSame(['timestamp', 'timestamp', 'NO', null, null], $this->column('created_at'));
        self::assertSame([['render_profile_id', 0], ['sequence_index', 0]], $this->index('uq_clip_reframe_keyframes_sequence'));
        self::assertSame([['render_profile_id', 0], ['at_ms', 0]], $this->index('uq_clip_reframe_keyframes_time'));
        self::assertSame(['clip_render_profiles', 'id', 'CASCADE'], $this->foreignKey('fk_clip_reframe_keyframes_profile'));
        $this->assertCheckMentions('chk_clip_reframe_keyframes_sequence', ['sequence_index', '<=31']);
        $this->assertCheckMentions('chk_clip_reframe_keyframes_time', ['at_ms', '<=180000']);
        $this->assertCheckMentions('chk_clip_reframe_keyframes_center_x', ['center_x', '<=1']);
        $this->assertCheckMentions('chk_clip_reframe_keyframes_center_y', ['center_y', '<=1']);
    }

    public function testRejectsDivergentSurvivingKeyframeTableBeforeLedgerInsert(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS clip_reframe_keyframes');
        $this->deleteLedger();
        $this->pdo->exec('CREATE TABLE clip_reframe_keyframes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Migration schema postcondition failed.');
            $this->migrator->run();
        } finally {
            $ledgerCount = $this->ledgerCount();
            $this->pdo->exec('DROP TABLE IF EXISTS clip_reframe_keyframes');
            $this->deleteLedger();
            (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
            self::assertSame(0, $ledgerCount);
        }
    }

    public function testRejectsDivergentDecimalPrecisionBeforeLedgerInsert(): void
    {
        $this->pdo->exec('ALTER TABLE clip_reframe_keyframes MODIFY center_x DECIMAL(8,6) UNSIGNED NOT NULL');
        $this->deleteLedger();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Migration schema postcondition failed.');
            $this->migrator->run();
        } finally {
            $ledgerCount = $this->ledgerCount();
            $this->pdo->exec('ALTER TABLE clip_reframe_keyframes MODIFY center_x DECIMAL(7,6) UNSIGNED NOT NULL');
            $this->deleteLedger();
            (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
            self::assertSame(0, $ledgerCount);
        }
    }

    public function testRejectsMissingPrimaryIndexBeforeLedgerInsert(): void
    {
        $this->pdo->exec('ALTER TABLE clip_reframe_keyframes DROP PRIMARY KEY, ADD UNIQUE INDEX future_keyframe_id (id)');
        $this->deleteLedger();

        try {
            $this->migrator->run();
            self::fail('A surviving table without PRIMARY must fail the postcondition.');
        } catch (RuntimeException $exception) {
            self::assertSame('Migration schema postcondition failed.', $exception->getMessage());
        } finally {
            $ledgerCount = $this->ledgerCount();
            $this->restoreKeyframeSchema();
            self::assertSame(0, $ledgerCount);
        }
    }

    public function testRejectsMissingAutoIncrementBeforeLedgerInsert(): void
    {
        $this->pdo->exec('ALTER TABLE clip_reframe_keyframes MODIFY id BIGINT UNSIGNED NOT NULL');
        $this->deleteLedger();

        try {
            $this->migrator->run();
            self::fail('A surviving table without AUTO_INCREMENT must fail the postcondition.');
        } catch (RuntimeException $exception) {
            self::assertSame('Migration schema postcondition failed.', $exception->getMessage());
        } finally {
            $ledgerCount = $this->ledgerCount();
            $this->restoreKeyframeSchema();
            self::assertSame(0, $ledgerCount);
        }
    }

    private function safeTestDsn(): string
    {
        $dsn = getenv('TEST_DB_DSN');
        self::assertIsString($dsn, 'TEST_DB_DSN must be configured for integration tests.');
        self::assertStringStartsWith('mysql:', strtolower(trim($dsn)));
        self::assertSame(1, preg_match_all('/(?:^|[;:])dbname=([^;]+)/i', $dsn, $matches));
        $database = strtolower(trim($matches[1][0], " \t\n\r\0\x0B`'\""));
        self::assertMatchesRegularExpression('/_test\z/D', $database, 'Integration tests require a dedicated *_test database.');
        return $dsn;
    }

    private function assertSupportedDatabase(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        $isMariaDb = stripos($version, 'mariadb') !== false;
        preg_match('/\d+\.\d+\.\d+/', $version, $matches);
        self::assertNotEmpty($matches);
        self::assertTrue(version_compare($matches[0], $isMariaDb ? '10.4.0' : '8.0.16', '>='));
        if ($isMariaDb) {
            self::assertSame(1, (int) $this->pdo->query('SELECT @@check_constraint_checks')->fetchColumn());
        }
    }

    private function restoreKeyframeSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS clip_reframe_keyframes');
        $this->deleteLedger();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
    }

    private function deleteLedger(): void
    {
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([self::MIGRATION]);
    }

    /** @return array{string,string,string,int|null,int|null} */
    private function column(string $column): array
    {
        $statement = $this->pdo->prepare('SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute(['clip_reframe_keyframes', $column]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $dataType = (string) $row['DATA_TYPE'];
        $columnType = (string) preg_replace('/^(bigint|int|smallint|tinyint)\(\d+\)( unsigned)?$/', '$1$2', (string) $row['COLUMN_TYPE']);

        $length = in_array($dataType, ['char', 'varchar'], true) ? (int) $row['CHARACTER_MAXIMUM_LENGTH'] : null;

        return [$dataType, $columnType, (string) $row['IS_NULLABLE'], $length, $dataType === 'decimal' ? (int) $row['NUMERIC_SCALE'] : null];
    }

    /** @return list<array{string,int}> */
    private function index(string $name): array
    {
        $statement = $this->pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
        $statement->execute(['clip_reframe_keyframes', $name]);
        return array_map(static fn (array $row): array => [(string) $row['COLUMN_NAME'], (int) $row['NON_UNIQUE']], $statement->fetchAll());
    }

    /** @return array{string,string,string} */
    private function foreignKey(string $name): array
    {
        $statement = $this->pdo->prepare('SELECT k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ?');
        $statement->execute(['clip_reframe_keyframes', $name]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        return [(string) $row['REFERENCED_TABLE_NAME'], (string) $row['REFERENCED_COLUMN_NAME'], (string) $row['DELETE_RULE']];
    }

    /** @param list<string> $needles */
    private function assertCheckMentions(string $name, array $needles): void
    {
        $statement = $this->pdo->prepare("SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc INNER JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'clip_reframe_keyframes' AND tc.CONSTRAINT_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK'");
        $statement->execute([$name]);
        $clause = strtolower(str_replace(['`', ' '], '', (string) $statement->fetchColumn()));
        self::assertNotSame('', $clause);
        foreach ($needles as $needle) {
            self::assertStringContainsString(strtolower(str_replace(' ', '', $needle)), $clause);
        }
    }

    private function ledgerCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
        $statement->execute([self::MIGRATION]);
        return (int) $statement->fetchColumn();
    }
}
