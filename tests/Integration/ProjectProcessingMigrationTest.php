<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ProjectProcessingMigrationTest extends TestCase
{
    private PDO $pdo;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
    }

    public function testCreatesProjectSourcesAndProcessingJobsIdempotently(): void
    {
        $this->migrator->run();
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')
            ->execute(['202609030002_create_project_processing_tables.sql']);

        self::assertSame(
            ['202609030002_create_project_processing_tables.sql'],
            array_values(array_filter(
                $this->migrator->run(),
                static fn (string $migration): bool => $migration === '202609030002_create_project_processing_tables.sql'
            ))
        );
        self::assertSame([], $this->migrator->run());
        self::assertSame(['processing_jobs', 'project_sources'], $this->existingTables(['project_sources', 'processing_jobs']));
        self::assertTrue($this->hasUniqueIndex('processing_jobs', ['queue_name', 'idempotency_key']));
        self::assertTrue($this->hasUniqueIndex('projects', ['user_id', 'ingest_key']));
    }

    public function testEnforcesProgressUniquenessAndProjectCascade(): void
    {
        $this->migrator->run();
        $suffix = bin2hex(random_bytes(6));
        $planId = $this->ensurePlan();
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)'
        );
        $statement->execute(['Migration test', "migration-{$suffix}@example.test", 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$userId, hash('sha256', $suffix), 'Migration project', 'queued', 0]);
            $projectId = (int) $this->pdo->lastInsertId();

            $sourceSql = 'INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, mime_type) VALUES (?, ?, ?, ?, ?)';
            $this->pdo->prepare($sourceSql)->execute([$projectId, 'upload', 'local', "users/{$userId}/{$suffix}.mp4", 'video/mp4']);
            $jobSql = 'INSERT INTO processing_jobs (type, project_id, payload_json, idempotency_key, available_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())';
            $this->pdo->prepare($jobSql)->execute(['probe_source', $projectId, '{}', hash('sha256', 'job-' . $suffix)]);

            try {
                $this->pdo->prepare($sourceSql)->execute([$projectId, 'upload', 'local', 'duplicate.mp4', 'video/mp4']);
                self::fail('A project must not accept more than one source.');
            } catch (PDOException $exception) {
                self::assertSame('23000', $exception->getCode());
            }

            try {
                $this->pdo->prepare('UPDATE projects SET progress = 101 WHERE id = ?')->execute([$projectId]);
                self::fail('Project progress above 100 must be rejected.');
            } catch (PDOException $exception) {
                self::assertSame('23000', $exception->getCode());
            }

            try {
                $this->pdo->prepare('UPDATE processing_jobs SET progress = 101 WHERE project_id = ?')->execute([$projectId]);
                self::fail('Job progress above 100 must be rejected.');
            } catch (PDOException $exception) {
                self::assertSame('23000', $exception->getCode());
            }

            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
            self::assertSame(0, $this->rowCount('project_sources', 'project_id', $projectId));
            self::assertSame(0, $this->rowCount('processing_jobs', 'project_id', $projectId));
        } finally {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $this->pdo->exec("DELETE FROM plans WHERE slug = 'migration-test'");
        }
    }

    /** @dataProvider unsafeDuplicateRecoveryCases */
    public function testDuplicateDdlOutsideTheExactRecoveryContractStillFails(string $migrationName, string $sql): void
    {
        $directory = sys_get_temp_dir() . '/migration-scope-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory . '/' . $migrationName;
        file_put_contents($path, $sql);
        $isPhaseTwoMigration = $migrationName === '202609030002_create_project_processing_tables.sql';
        if ($isPhaseTwoMigration) {
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migrationName]);
        }

        try {
            try {
                (new Migrator($this->pdo, $directory))->run();
                self::fail('Unexpected duplicate DDL must not be recorded as a successful migration.');
            } catch (PDOException $exception) {
                self::assertContains((int) $exception->errorInfo[1], [1060, 1061]);
            }

            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
            $statement->execute([$migrationName]);
            self::assertSame(0, (int) $statement->fetchColumn());
        } finally {
            unlink($path);
            rmdir($directory);
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migrationName]);
            if ($isPhaseTwoMigration) {
                $this->migrator->run();
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public function unsafeDuplicateRecoveryCases(): iterable
    {
        yield 'expected column in another migration' => [
            '999999999999_other_migration.sql',
            'ALTER TABLE projects ADD COLUMN ingest_key CHAR(64) NULL AFTER user_id;',
        ];
        yield 'expected index in another migration' => [
            '999999999998_other_index.sql',
            'ALTER TABLE projects ADD UNIQUE INDEX uq_projects_user_ingest (user_id, ingest_key);',
        ];
        yield 'different column in the scoped migration' => [
            '202609030002_create_project_processing_tables.sql',
            'ALTER TABLE projects ADD COLUMN name VARCHAR(255) NOT NULL;',
        ];
        yield 'combined statement in the scoped migration' => [
            '202609030002_create_project_processing_tables.sql',
            'ALTER TABLE projects ADD COLUMN ingest_key CHAR(64) NULL AFTER user_id, ADD COLUMN unexpected_column INT NULL;',
        ];
    }

    public function testScopedRecoveryRejectsAnExistingColumnWithTheWrongDefinition(): void
    {
        $migrationName = '202609030002_create_project_processing_tables.sql';
        $directory = sys_get_temp_dir() . '/migration-definition-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory . '/' . $migrationName;
        file_put_contents($path, 'ALTER TABLE projects ADD COLUMN error_code VARCHAR(64) NULL AFTER progress;');
        $this->pdo->exec('ALTER TABLE projects MODIFY COLUMN error_code VARCHAR(32) NULL');
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migrationName]);

        try {
            try {
                (new Migrator($this->pdo, $directory))->run();
                self::fail('A divergent existing column must not be accepted as partial recovery.');
            } catch (PDOException $exception) {
                self::assertSame(1060, (int) $exception->errorInfo[1]);
            }

            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
            $statement->execute([$migrationName]);
            self::assertSame(0, (int) $statement->fetchColumn());
        } finally {
            unlink($path);
            rmdir($directory);
            $this->pdo->exec('ALTER TABLE projects MODIFY COLUMN error_code VARCHAR(64) NULL');
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migrationName]);
            $this->migrator->run();
        }
    }

    /** @dataProvider nullableColumnsWithUnexpectedDefaults */
    public function testScopedRecoveryRejectsUnexpectedDefaultsOnNullableColumns(
        string $column,
        string $definition,
        string $migrationSql,
        string $defaultSql
    ): void {
        $migrationName = '202609030002_create_project_processing_tables.sql';
        $directory = sys_get_temp_dir() . '/migration-default-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory . '/' . $migrationName;
        file_put_contents($path, $migrationSql);
        $this->pdo->exec("ALTER TABLE projects MODIFY COLUMN {$column} {$definition} DEFAULT {$defaultSql}");
        $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migrationName]);

        try {
            try {
                (new Migrator($this->pdo, $directory))->run();
                self::fail('A nullable column with a non-null default must not be accepted as partial recovery.');
            } catch (PDOException $exception) {
                self::assertSame(1060, (int) $exception->errorInfo[1]);
            }

            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
            $statement->execute([$migrationName]);
            self::assertSame(0, (int) $statement->fetchColumn());
        } finally {
            unlink($path);
            rmdir($directory);
            $this->pdo->exec("ALTER TABLE projects MODIFY COLUMN {$column} {$definition} DEFAULT NULL");
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migrationName]);
            $this->migrator->run();
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public function nullableColumnsWithUnexpectedDefaults(): iterable
    {
        yield 'ingest key' => [
            'ingest_key',
            'CHAR(64) NULL',
            'ALTER TABLE projects ADD COLUMN ingest_key CHAR(64) NULL AFTER user_id;',
            "'fixed'",
        ];
        yield 'error code' => [
            'error_code',
            'VARCHAR(64) NULL',
            'ALTER TABLE projects ADD COLUMN error_code VARCHAR(64) NULL AFTER progress;',
            "'fixed'",
        ];
        yield 'error message' => [
            'error_message',
            'VARCHAR(255) NULL',
            'ALTER TABLE projects ADD COLUMN error_message VARCHAR(255) NULL AFTER error_code;',
            "'fixed'",
        ];
        yield 'quoted NULL remains a literal' => [
            'ingest_key',
            'CHAR(64) NULL',
            'ALTER TABLE projects ADD COLUMN ingest_key CHAR(64) NULL AFTER user_id;',
            "'NULL'",
        ];
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
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([$table]);
        $indexes = [];
        foreach ($statement->fetchAll() as $row) {
            $indexes[$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
        }

        return in_array($columns, $indexes, true);
    }

    private function ensurePlan(): int
    {
        $this->pdo->exec(
            "INSERT INTO plans (slug, name, features) VALUES ('migration-test', 'Migration Test', JSON_OBJECT()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function rowCount(string $table, string $column, int $value): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $statement->execute([$value]);

        return (int) $statement->fetchColumn();
    }
}
