<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SupportedDatabaseServer;

final class AiAnalysisMigrationTest extends TestCase
{
    private const MIGRATION = '202609030003_create_ai_analysis_tables.sql';
    private const RENDER_MIGRATION = '202609040001_extend_clips_for_rendering.sql';
    private const CLEANUP_MIGRATION = '202609040002_create_render_artifact_cleanup_outbox.sql';
    private const CLEANUP_UPGRADE_MIGRATION = '202609040003_upgrade_render_artifact_cleanup_reservations.sql';
    private const RENDER_PROFILE_MIGRATION = '202609040004_create_clip_render_profiles.sql';
    private const REFRAME_KEYFRAME_MIGRATION = '202609040005_create_clip_reframe_keyframes.sql';

    private PDO $pdo;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $dsn = $this->safeTestDsn();

        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->assertSupportedDatabase();
        $this->migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $this->migrator->run();
    }

    public function testCreatesAiTablesAndIdempotencyIndexes(): void
    {
        self::assertSame(
            ['ai_analyses', 'clips', 'credit_reservations'],
            $this->existingTables(['ai_analyses', 'clips', 'credit_reservations'])
        );

        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([self::MIGRATION]);
        self::assertSame([self::MIGRATION], $this->migrator->run());
        self::assertSame([], $this->migrator->run());

        self::assertTrue($this->hasUniqueIndex('ai_analyses', ['project_id', 'prompt_version']));
        self::assertTrue($this->hasUniqueIndex('clips', ['ai_analysis_id', 'suggestion_index']));
        self::assertTrue($this->hasUniqueIndex('credit_reservations', ['user_id', 'idempotency_key']));
        self::assertTrue($this->hasIndex('ai_analyses', ['status', 'created_at']));
        self::assertTrue($this->hasIndex('clips', ['project_id', 'status', 'viral_score']));
        self::assertTrue($this->hasIndex('credit_reservations', ['project_id', 'status']));
    }

    public function testColumnBoundsAndJsonTypeAreVisibleInDatabaseMetadata(): void
    {
        self::assertSame(32, $this->characterLength('ai_analyses', 'prompt_version'));
        self::assertSame(100, $this->characterLength('ai_analyses', 'model'));
        self::assertSame(128, $this->characterLength('ai_analyses', 'gemini_file_name'));
        self::assertSame(1024, $this->characterLength('ai_analyses', 'gemini_file_uri'));
        self::assertSame(180, $this->characterLength('clips', 'title'));
        self::assertSame(500, $this->characterLength('clips', 'hook'));
        self::assertSame(1000, $this->characterLength('clips', 'reason'));
        self::assertSame(64, $this->characterLength('credit_reservations', 'idempotency_key'));
        self::assertTrue($this->columnHasJsonSemantics('ai_analyses', 'validated_response_json'));
    }

    public function testCreatesClipRenderingColumns(): void
    {
        self::assertTrue($this->hasColumn('clips', 'render_start_time'));
        self::assertTrue($this->hasColumn('clips', 'render_end_time'));
        self::assertTrue($this->hasColumn('clips', 'render_revision'));
        self::assertTrue($this->hasColumn('clips', 'render_error_code'));
        self::assertTrue($this->hasColumn('clips', 'output_size_bytes'));
        self::assertTrue($this->hasColumn('clips', 'thumbnail_size_bytes'));
        self::assertTrue($this->hasColumn('clips', 'approved_at'));
        self::assertTrue($this->hasColumn('clips', 'render_requested_at'));
        self::assertTrue($this->hasColumn('clips', 'rendered_at'));
    }

    public function testCleanupOutboxMigrationReappliesWhenTheTableSurvivedWithoutLedger(): void
    {
        self::assertSame(
            ['render_artifact_cleanups'],
            $this->existingTables(['render_artifact_cleanups'])
        );
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')
            ->execute([self::CLEANUP_MIGRATION]);
        $reapplied = false;

        try {
            $reapplied = in_array(self::CLEANUP_MIGRATION, $this->migrator->run(), true);
        } catch (PDOException) {
            $reapplied = false;
        } finally {
            $this->pdo->prepare(
                'INSERT IGNORE INTO migrations (migration) VALUES (?)'
            )->execute([self::CLEANUP_MIGRATION]);
        }

        self::assertTrue($reapplied, 'The existing outbox table must not block migration ledger recovery.');
    }

    public function testCleanupUpgradeUsesMySql8CompatibleAlterSyntax(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/' . self::CLEANUP_UPGRADE_MIGRATION
        );

        self::assertNotFalse($sql);
        self::assertStringNotContainsString('ADD COLUMN IF NOT EXISTS', $sql);
    }

    public function testCleanupUpgradeReappliesWhenColumnsSurvivedWithoutLedger(): void
    {
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')
            ->execute([self::CLEANUP_UPGRADE_MIGRATION]);

        self::assertSame([self::CLEANUP_UPGRADE_MIGRATION], $this->migrator->run());
        self::assertSame([], $this->migrator->run());
    }

    public function testForeignKeysExposeExpectedTargetsAndDeleteRules(): void
    {
        self::assertSame(['projects', 'id', 'CASCADE'], $this->foreignKey('ai_analyses', 'project_id'));
        self::assertSame(['users', 'id', 'CASCADE'], $this->foreignKey('credit_reservations', 'user_id'));
        self::assertSame(['projects', 'id', 'CASCADE'], $this->foreignKey('credit_reservations', 'project_id'));
        self::assertSame(['credit_transactions', 'id', 'RESTRICT'], $this->foreignKey('credit_reservations', 'credit_transaction_id'));
        self::assertSame(['credit_transactions', 'id', 'RESTRICT'], $this->foreignKey('credit_reservations', 'refund_transaction_id'));
        self::assertSame(['projects', 'id', 'CASCADE'], $this->foreignKey('clips', 'project_id'));
        self::assertSame(['ai_analyses', 'id', 'CASCADE'], $this->foreignKey('clips', 'ai_analysis_id'));
    }

    public function testProjectDeletionCascadesAnalysesReservationsAndClips(): void
    {
        [$planId, $userId, $projectId] = $this->createProjectFixture();

        try {
            $this->pdo->prepare(
                "INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, 'test-v1', 'test-model', 'validating', ?)"
            )->execute([$projectId, '{"video_summary":"ok","clips":[]}']);
            $analysisId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO credit_reservations (user_id, project_id, units, idempotency_key) VALUES (?, ?, 1, ?)"
            )->execute([$userId, $projectId, hash('sha256', 'migration-' . $projectId)]);
            $reservationId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category) VALUES (?, ?, 0, 'Clip', 0, 20, 20, 90, 'Hook', 'Reason', 'other')"
            )->execute([$projectId, $analysisId]);
            $clipId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);

            self::assertSame(0, $this->rowCount('ai_analyses', 'id', $analysisId));
            self::assertSame(0, $this->rowCount('credit_reservations', 'id', $reservationId));
            self::assertSame(0, $this->rowCount('clips', 'id', $clipId));
        } finally {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$planId]);
        }
    }

    public function testValidatedResponseColumnRejectsMalformedJson(): void
    {
        [$planId, $userId, $projectId] = $this->createProjectFixture();

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, 'json-v1', 'test-model', 'validating', ?)"
            );
            $rejected = false;
            try {
                $statement->execute([$projectId, '{not-json']);
            } catch (PDOException) {
                $rejected = true;
            }

            self::assertTrue($rejected, 'The JSON column must reject malformed provider output.');
        } finally {
            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$planId]);
        }
    }

    public function testRecoversWhenOnlyTheFirstTableSurvivedPartialDdl(): void
    {
        self::assertSame(
            ['ai_analyses', 'clips', 'credit_reservations'],
            $this->existingTables(['ai_analyses', 'clips', 'credit_reservations'])
        );

        try {
            // Newer dependants must be removed first; all fixture DDL is restored even on a failed drop.
            foreach (['clip_subtitle_cues', 'clip_subtitle_tracks', 'clip_editor_profiles', 'clip_reframe_keyframes', 'clip_render_profiles', 'clips', 'credit_reservations'] as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([self::MIGRATION]);
            self::assertSame([self::MIGRATION], $this->migrator->run());
            self::assertSame(
                ['ai_analyses', 'clips', 'credit_reservations'],
                $this->existingTables(['ai_analyses', 'clips', 'credit_reservations'])
            );
            self::assertTrue($this->hasUniqueIndex('clips', ['ai_analysis_id', 'suggestion_index']));
            self::assertTrue($this->hasUniqueIndex('credit_reservations', ['user_id', 'idempotency_key']));
        } finally {
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([self::RENDER_MIGRATION]);
            $this->pdo->prepare('DELETE FROM migrations WHERE migration IN (?, ?)')
                ->execute([self::RENDER_PROFILE_MIGRATION, self::REFRAME_KEYFRAME_MIGRATION]);
            $this->pdo->exec("DELETE FROM migrations WHERE migration IN ('202609060001_create_clip_editor_profiles.sql', '202609060002_create_clip_subtitle_tracks.sql', '202609060003_create_clip_subtitle_cues.sql')");
            $this->migrator->run();
        }
    }

    /** @param list<string> $tables @return list<string> */
    private function existingTables(array $tables): array
    {
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $statement = $this->pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME"
        );
        $statement->execute($tables);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $columns */
    private function hasUniqueIndex(string $table, array $columns): bool
    {
        return $this->hasIndex($table, $columns, true);
    }

    /** @param list<string> $columns */
    private function hasIndex(string $table, array $columns, bool $uniqueOnly = false): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([$table]);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($uniqueOnly && (int) $row['NON_UNIQUE'] !== 0) {
                continue;
            }
            $indexes[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        return in_array($columns, $indexes, true);
    }

    private function characterLength(string $table, string $column): int
    {
        $statement = $this->pdo->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn();
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
        $metadata = $this->pdo->query(
            'SELECT VERSION() AS server_version, @@version_comment AS version_comment'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($metadata);
        $version = (string) $metadata['server_version'];
        $engine = SupportedDatabaseServer::identify($version, (string) $metadata['version_comment']);
        preg_match('/\d+\.\d+\.\d+/', $version, $matches);
        self::assertNotEmpty($matches);
        self::assertTrue(version_compare($matches[0], $engine === 'mariadb' ? '10.4.0' : '8.0.16', '>='));
        if ($engine === 'mariadb') {
            self::assertSame(1, (int) $this->pdo->query('SELECT @@check_constraint_checks')->fetchColumn());
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return $statement->fetchColumn() !== false;
    }

    private function columnHasJsonSemantics(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        if (strtolower((string) $statement->fetchColumn()) === 'json') {
            return true;
        }

        $statement = $this->pdo->prepare(
            'SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc '
            . 'INNER JOIN information_schema.TABLE_CONSTRAINTS tc '
            . 'ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME '
            . "WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK'"
        );
        $statement->execute([$table]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $clause) {
            $normalized = strtolower(str_replace('`', '', (string) $clause));
            if (str_contains($normalized, 'json_valid') && str_contains($normalized, strtolower($column))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, string, string} */
    private function foreignKey(string $table, string $column): array
    {
        $statement = $this->pdo->prepare(
            'SELECT kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE '
            . 'FROM information_schema.KEY_COLUMN_USAGE kcu '
            . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc '
            . 'ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
            . 'WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = ? AND kcu.COLUMN_NAME = ? '
            . 'AND kcu.REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $statement->execute([$table, $column]);
        $row = $statement->fetch(PDO::FETCH_NUM);

        self::assertIsArray($row, "Missing foreign key for {$table}.{$column}.");

        return [(string) $row[0], (string) $row[1], (string) $row[2]];
    }

    /** @return array{int, int, int} */
    private function createProjectFixture(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare(
            'INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())'
        )->execute(['ai-migration-' . $suffix, 'AI Migration ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AI migration', "ai-migration-{$suffix}@example.test", 'not-a-real-hash', $planId, 10]);
        $userId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'AI migration project', 'ready', 70)"
        )->execute([$userId]);
        $projectId = (int) $this->pdo->lastInsertId();

        return [$planId, $userId, $projectId];
    }

    private function rowCount(string $table, string $column, int $value): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $statement->execute([$value]);

        return (int) $statement->fetchColumn();
    }
}
