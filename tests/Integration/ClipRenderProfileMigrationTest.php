<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SafePhase5TestDatabase;

final class ClipRenderProfileMigrationTest extends TestCase
{
    private const MIGRATION = '202609040004_create_clip_render_profiles.sql';

    private PDO $pdo;
    private string $directory;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $dsn = $this->safeTestDsn();
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->assertSupportedDatabase();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->directory = sys_get_temp_dir() . '/clip-render-profile-migration-' . bin2hex(random_bytes(8));
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

    public function testCreatesExactProfileSchemaAndRecoversADeletedLedgerEntry(): void
    {
        $this->deleteLedger();
        self::assertContains(self::MIGRATION, $this->migrator->run());
        $this->deleteLedger();
        self::assertContains(self::MIGRATION, $this->migrator->run());
        self::assertSame([], $this->migrator->run());

        self::assertSame(['bigint', 'bigint unsigned', 'NO', null], $this->column('clip_render_profiles', 'id'));
        self::assertSame(['bigint', 'bigint unsigned', 'NO', null], $this->column('clip_render_profiles', 'clip_id'));
        self::assertSame(['int', 'int unsigned', 'NO', null], $this->column('clip_render_profiles', 'render_revision'));
        self::assertSame(["enum", "enum('original','9:16','1:1','16:9','4:5')", 'NO', null], $this->column('clip_render_profiles', 'aspect_ratio'));
        self::assertSame(["enum", "enum('original','center','manual','auto')", 'NO', null], $this->column('clip_render_profiles', 'reframe_mode'));
        self::assertSame(['smallint', 'smallint unsigned', 'YES', null], $this->column('clip_render_profiles', 'output_width'));
        self::assertSame(['smallint', 'smallint unsigned', 'YES', null], $this->column('clip_render_profiles', 'output_height'));
        self::assertSame(['varchar', 'varchar(64)', 'YES', 64], $this->column('clip_render_profiles', 'detector_version'));
        self::assertSame(['timestamp', 'timestamp', 'NO', null], $this->column('clip_render_profiles', 'created_at'));

        self::assertSame([['clip_id', 0], ['render_revision', 0]], $this->index('clip_render_profiles', 'uq_clip_render_profiles_revision'));
        self::assertSame([['clip_id', 1], ['created_at', 1]], $this->index('clip_render_profiles', 'idx_clip_render_profiles_clip_created'));
        self::assertSame(['clips', 'id', 'CASCADE'], $this->foreignKey('clip_render_profiles', 'fk_clip_render_profiles_clip'));
        self::assertCheckMentions('clip_render_profiles', 'chk_clip_render_profiles_revision', ['render_revision', '>0']);
        self::assertCheckMentions('clip_render_profiles', 'chk_clip_render_profiles_shape', ['aspect_ratio', 'reframe_mode', 'output_width', 'output_height', "'9:16'", '1280']);
        self::assertCheckMentions('clip_render_profiles', 'chk_clip_render_profiles_detector', ['reframe_mode', 'detector_version', "'auto'", 'tasks-vision-1.0.1/blazeface-short-f16-r1']);
    }

    public function testRejectsDivergentSurvivingProfileTableBeforeLedgerInsert(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS clip_reframe_keyframes');
        $this->pdo->exec('DROP TABLE IF EXISTS clip_render_profiles');
        $this->deleteLedger();
        $this->pdo->exec('CREATE TABLE clip_render_profiles (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Migration schema postcondition failed.');
            $this->migrator->run();
        } finally {
            $ledgerCount = $this->ledgerCount();
            $this->restoreProfileSchema();
            self::assertSame(0, $ledgerCount);
        }
    }

    public function testRejectsSemanticallyRegroupedDetectorCheckBeforeLedgerInsert(): void
    {
        $this->dropCheck('clip_render_profiles', 'chk_clip_render_profiles_detector');
        $this->pdo->exec(
            "ALTER TABLE clip_render_profiles ADD CONSTRAINT chk_clip_render_profiles_detector CHECK ("
            . "reframe_mode = 'auto' AND detector_version IS NOT NULL "
            . "AND (detector_version = 'tasks-vision-1.0.1/blazeface-short-f16-r1' OR reframe_mode <> 'auto') "
            . 'AND detector_version IS NULL)'
        );
        $this->deleteLedger();

        try {
            $this->migrator->run();
            self::fail('A semantically regrouped CHECK must fail the postcondition.');
        } catch (RuntimeException $exception) {
            self::assertSame('Migration schema postcondition failed.', $exception->getMessage());
        } finally {
            $ledgerCount = $this->ledgerCount();
            $this->restoreProfileSchema();
            self::assertSame(0, $ledgerCount);
        }
    }

    public function testAllowsFutureColumnsAndIndexesOnSurvivingProfileTable(): void
    {
        $this->pdo->exec('ALTER TABLE clip_render_profiles ADD COLUMN future_note VARCHAR(20) NULL');
        $this->pdo->exec('ALTER TABLE clip_render_profiles ADD INDEX idx_future_note (future_note)');
        $this->deleteLedger();

        try {
            self::assertContains(self::MIGRATION, $this->migrator->run());
            self::assertSame([], $this->migrator->run());
            self::assertSame(['varchar', 'varchar(20)', 'YES', 20], $this->column('clip_render_profiles', 'future_note'));
            self::assertSame([['future_note', 1]], $this->index('clip_render_profiles', 'idx_future_note'));
        } finally {
            $this->pdo->exec('ALTER TABLE clip_render_profiles DROP INDEX idx_future_note, DROP COLUMN future_note');
        }
    }

    public function testRestoringLegacyFixtureAlsoRestoresTheAppliedDimensionUpgrade(): void
    {
        $this->restoreProfileSchema();
        $this->assertCheckMentions('clip_render_profiles', 'chk_clip_render_profiles_shape', ['1080', '1920', '1350']);
    }

    private function safeTestDsn(): string
    {
        return SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
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

    private function dropCheck(string $table, string $name): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        $operation = stripos($version, 'mariadb') !== false ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $this->pdo->exec("ALTER TABLE {$table} {$operation} {$name}");
    }

    private function restoreProfileSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS clip_reframe_keyframes');
        $this->pdo->exec('DROP TABLE IF EXISTS clip_render_profiles');
        $this->pdo->prepare('DELETE FROM migrations WHERE migration IN (?, ?, ?)')->execute([
            self::MIGRATION,
            '202609040005_create_clip_reframe_keyframes.sql',
            '202609060044_upgrade_render_profile_dimensions.sql',
        ]);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
    }

    private function deleteLedger(): void
    {
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([self::MIGRATION]);
    }

    /** @return array{string,string,string,int|null} */
    private function column(string $table, string $column): array
    {
        $statement = $this->pdo->prepare('SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute([$table, $column]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        $columnType = (string) preg_replace('/^(bigint|int|smallint|tinyint)\(\d+\)( unsigned)?$/', '$1$2', (string) $row['COLUMN_TYPE']);

        $length = in_array($row['DATA_TYPE'], ['char', 'varchar'], true) ? (int) $row['CHARACTER_MAXIMUM_LENGTH'] : null;

        return [(string) $row['DATA_TYPE'], $columnType, (string) $row['IS_NULLABLE'], $length];
    }

    /** @return list<array{string,int}> */
    private function index(string $table, string $name): array
    {
        $statement = $this->pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
        $statement->execute([$table, $name]);

        return array_map(static fn (array $row): array => [(string) $row['COLUMN_NAME'], (int) $row['NON_UNIQUE']], $statement->fetchAll());
    }

    /** @return array{string,string,string} */
    private function foreignKey(string $table, string $name): array
    {
        $statement = $this->pdo->prepare('SELECT k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ?');
        $statement->execute([$table, $name]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return [(string) $row['REFERENCED_TABLE_NAME'], (string) $row['REFERENCED_COLUMN_NAME'], (string) $row['DELETE_RULE']];
    }

    /** @param list<string> $needles */
    private function assertCheckMentions(string $table, string $name, array $needles): void
    {
        $statement = $this->pdo->prepare("SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc INNER JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK'");
        $statement->execute([$table, $name]);
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
