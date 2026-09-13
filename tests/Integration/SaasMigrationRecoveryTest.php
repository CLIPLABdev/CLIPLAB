<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;
use Throwable;

final class SaasMigrationRecoveryTest extends TestCase
{
    private const EDITOR = '202609060001_create_clip_editor_profiles.sql';
    private const TRACKS = '202609060002_create_clip_subtitle_tracks.sql';
    private const CUES = '202609060003_create_clip_subtitle_cues.sql';
    private const AUTO_EXPORT = '202609060004_add_project_automatic_exports.sql';
    private const USAGE = '202609060010_add_project_usage_recorded_at.sql';
    private const MIGRATIONS = [self::EDITOR, self::TRACKS, self::CUES, self::AUTO_EXPORT, self::USAGE];

    private PDO $pdo;
    private string $directory;
    private bool $schemaMutated = false;

    protected function setUp(): void
    {
        if (getenv('ALLOW_SCHEMA_RECOVERY_TEST') !== '1') {
            self::markTestSkipped('Set ALLOW_SCHEMA_RECOVERY_TEST=1 for the isolated destructive schema gate.');
        }
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $database = strtolower((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertMatchesRegularExpression('/\A[a-z0-9_]+_test\z/D', $database);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->assertDestructiveFixtureIsEmpty();

        $this->directory = sys_get_temp_dir() . '/saas-migrations-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        foreach (self::MIGRATIONS as $migration) {
            self::assertTrue(copy(
                dirname(__DIR__, 2) . '/database/migrations/' . $migration,
                $this->directory . '/' . $migration
            ));
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        $temporaryRoot = realpath(sys_get_temp_dir());
        $directory = realpath($this->directory);
        if (!is_string($temporaryRoot)
            || !is_string($directory)
            || dirname($directory) !== $temporaryRoot
            || !str_starts_with(basename($directory), 'saas-migrations-')
        ) {
            throw new \RuntimeException('Unsafe migration test directory.');
        }

        try {
            if ($this->schemaMutated && isset($this->pdo)) {
                $this->resetTargetSchema();
                (new Migrator($this->pdo, $directory))->run();
            }
        } finally {
            foreach (self::MIGRATIONS as $migration) {
                $copy = $directory . DIRECTORY_SEPARATOR . $migration;
                if (is_file($copy)) {
                    @unlink($copy);
                }
            }
            @rmdir($directory);
        }
    }

    public function testFreshSchemaAndSecondRunAreDeterministic(): void
    {
        $this->schemaMutated = true;
        $this->resetTargetSchema();
        $migrator = new Migrator($this->pdo, $this->directory);

        self::assertSame(self::MIGRATIONS, $migrator->run());
        self::assertSame([], $migrator->run());
        self::assertTrue($this->hasColumn('clip_editor_profiles', 'options_json'));
        self::assertTrue($this->hasColumn('clip_subtitle_tracks', 'status'));
        self::assertTrue($this->hasColumn('clip_subtitle_cues', 'words_json'));
        self::assertTrue($this->hasColumn('projects', 'auto_render_requested'));
        self::assertTrue($this->hasColumn('projects', 'usage_recorded_at'));
        self::assertSame(
            ['user_id', 'usage_recorded_at'],
            $this->indexColumns('projects', 'idx_projects_user_usage')
        );
    }

    public function testExactDdlSurvivorsRecoverMissingLedgerRows(): void
    {
        $this->schemaMutated = true;
        $this->deleteLedger(self::MIGRATIONS);
        $migrator = new Migrator($this->pdo, $this->directory);

        self::assertSame(self::MIGRATIONS, $migrator->run());
        self::assertSame([], $migrator->run());
    }

    public function testEditorSurvivorWithWrongParentDeleteRuleIsRejected(): void
    {
        $this->schemaMutated = true;
        $this->pdo->exec('DROP TABLE clip_subtitle_cues');
        $this->pdo->exec('DROP TABLE clip_subtitle_tracks');
        $this->pdo->exec('DROP TABLE clip_editor_profiles');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE clip_editor_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clip_id BIGINT UNSIGNED NOT NULL,
    render_revision INT UNSIGNED NOT NULL,
    parent_clip_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    options_json JSON NOT NULL,
    transcript_mode ENUM('none','manual','auto') NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_editor_clip_revision (clip_id, render_revision),
    UNIQUE KEY uq_editor_user_request (user_id, request_key),
    CONSTRAINT fk_editor_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE,
    CONSTRAINT fk_editor_parent FOREIGN KEY (parent_clip_id) REFERENCES clips(id) ON DELETE CASCADE,
    CONSTRAINT fk_editor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_editor_duration CHECK (duration_ms BETWEEN 1000 AND 180000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->deleteLedger([self::EDITOR]);

        $this->assertMigrationRejected(self::EDITOR);
    }

    public function testTrackSurvivorWithNullableStatusIsRejected(): void
    {
        $this->schemaMutated = true;
        $this->pdo->exec('DROP TABLE clip_subtitle_cues');
        $this->pdo->exec('DROP TABLE clip_subtitle_tracks');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE clip_subtitle_tracks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    editor_profile_id BIGINT UNSIGNED NOT NULL,
    language VARCHAR(35) NOT NULL DEFAULT 'und',
    status ENUM('pending','ready','failed') NULL DEFAULT 'pending',
    error_code VARCHAR(64) NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subtitle_editor (editor_profile_id),
    CONSTRAINT fk_subtitle_editor FOREIGN KEY (editor_profile_id) REFERENCES clip_editor_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->deleteLedger([self::TRACKS]);

        $this->assertMigrationRejected(self::TRACKS);
    }

    public function testCueSurvivorWithoutJsonSemanticsIsRejected(): void
    {
        $this->schemaMutated = true;
        $this->pdo->exec('DROP TABLE clip_subtitle_cues');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE clip_subtitle_cues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    track_id BIGINT UNSIGNED NOT NULL,
    cue_index SMALLINT UNSIGNED NOT NULL,
    start_ms INT UNSIGNED NOT NULL,
    end_ms INT UNSIGNED NOT NULL,
    text VARCHAR(350) NOT NULL,
    words_json LONGTEXT NULL,
    UNIQUE KEY uq_subtitle_cue (track_id, cue_index),
    CONSTRAINT fk_cue_track FOREIGN KEY (track_id) REFERENCES clip_subtitle_tracks(id) ON DELETE CASCADE,
    CONSTRAINT chk_cue_interval CHECK (start_ms < end_ms AND end_ms <= 180000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->deleteLedger([self::CUES]);

        $this->assertMigrationRejected(self::CUES);
    }

    public function testAutomaticExportSurvivorWithWrongDefaultIsRejected(): void
    {
        $this->schemaMutated = true;
        $this->dropColumnIfExists('projects', 'auto_render_requested');
        $this->pdo->exec('ALTER TABLE projects ADD COLUMN auto_render_requested TINYINT UNSIGNED NOT NULL DEFAULT 1');
        $this->deleteLedger([self::AUTO_EXPORT]);

        $this->assertMigrationRejected(self::AUTO_EXPORT);
    }

    public function testUsageSurvivorWithUniqueIndexIsRejected(): void
    {
        $this->schemaMutated = true;
        $this->dropIndexIfExists('projects', 'idx_projects_user_usage');
        $this->dropColumnIfExists('projects', 'usage_recorded_at');
        $this->pdo->exec('ALTER TABLE projects ADD COLUMN usage_recorded_at DATETIME NULL AFTER processed_duration_seconds');
        $this->pdo->exec('ALTER TABLE projects ADD UNIQUE INDEX idx_projects_user_usage (user_id, usage_recorded_at)');
        $this->deleteLedger([self::USAGE]);

        $this->assertMigrationRejected(self::USAGE);
    }

    private function assertMigrationRejected(string $migration): void
    {
        $rejected = false;
        try {
            (new Migrator($this->pdo, $this->directory))->run();
        } catch (Throwable) {
            $rejected = true;
        }

        self::assertTrue($rejected, 'A divergent DDL survivor must be rejected.');
        self::assertSame(0, $this->ledgerCount($migration));
    }

    private function resetTargetSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS clip_subtitle_cues');
        $this->pdo->exec('DROP TABLE IF EXISTS clip_subtitle_tracks');
        $this->pdo->exec('DROP TABLE IF EXISTS clip_editor_profiles');
        $this->dropIndexIfExists('projects', 'idx_projects_user_usage');
        $this->dropColumnIfExists('projects', 'usage_recorded_at');
        $this->dropColumnIfExists('projects', 'auto_render_requested');
        $this->deleteLedger(self::MIGRATIONS);
    }

    private function assertDestructiveFixtureIsEmpty(): void
    {
        foreach (['projects', 'clip_editor_profiles', 'clip_subtitle_tracks', 'clip_subtitle_cues'] as $table) {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            self::assertSame(0, $count, "Schema recovery gate requires an empty {$table} fixture.");
        }
    }

    /** @param list<string> $migrations */
    private function deleteLedger(array $migrations): void
    {
        $placeholders = implode(', ', array_fill(0, count($migrations), '?'));
        $statement = $this->pdo->prepare("DELETE FROM migrations WHERE migration IN ({$placeholders})");
        $statement->execute($migrations);
    }

    private function ledgerCount(string $migration): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
        $statement->execute([$migration]);

        return (int) $statement->fetchColumn();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX'
        );
        $statement->execute([$table, $index]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if ($this->hasColumn($table, $column)) {
            $this->pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->indexColumns($table, $index) !== []) {
            $this->pdo->exec("ALTER TABLE {$table} DROP INDEX {$index}");
        }
    }
}
