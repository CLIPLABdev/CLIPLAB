<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseMigrationTest extends TestCase
{
    public function testCreatesFoundationSchemaAndPlanSeed(): void
    {
        $pdo = $this->connection();
        $root = dirname(__DIR__, 2);
        $migrator = new Migrator($pdo, $root . '/database/migrations');

        $migrator->run();
        $pdo->exec((string) file_get_contents($root . '/database/seeds/plans.sql'));

        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plans', 'users', 'password_reset_tokens', 'rate_limits', 'projects', 'credit_transactions')"
        )->fetchAll(PDO::FETCH_COLUMN);

        sort($tables);

        self::assertSame(
            ['credit_transactions', 'password_reset_tokens', 'plans', 'projects', 'rate_limits', 'users'],
            $tables
        );
        self::assertSame(['Business', 'Free', 'Pro'], $pdo->query('SELECT name FROM plans ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRecoversWhenTheFirstFoundationTableAlreadyExists(): void
    {
        $pdo = $this->connection();
        $root = dirname(__DIR__, 2);
        $migrationName = '001_foundation_recovery_' . bin2hex(random_bytes(4)) . '.sql';
        $directory = sys_get_temp_dir() . '/migration-recovery-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $sql = file_get_contents($root . '/database/migrations/202609030001_create_foundation_tables.sql');
        self::assertNotFalse($sql);
        $prefix = 'migration_recovery_' . bin2hex(random_bytes(4));
        $tables = [
            'plans' => $prefix . '_plans',
            'users' => $prefix . '_users',
            'password_reset_tokens' => $prefix . '_password_reset_tokens',
            'rate_limits' => $prefix . '_rate_limits',
            'projects' => $prefix . '_projects',
            'credit_transactions' => $prefix . '_credit_transactions',
        ];

        foreach ($tables as $from => $to) {
            $sql = preg_replace('/\\b' . preg_quote($from, '/') . '\\b/', $to, $sql);
        }

        foreach (['fk_users_plan', 'fk_password_reset_tokens_user', 'fk_projects_user', 'fk_credit_transactions_user'] as $constraint) {
            $sql = str_replace($constraint, $prefix . '_' . $constraint, $sql);
        }

        $path = $directory . '/' . $migrationName;
        file_put_contents($path, $sql);

        try {
            $firstStatementEnd = strpos($sql, ';');
            self::assertNotFalse($firstStatementEnd);
            $pdo->exec(substr($sql, 0, $firstStatementEnd + 1));

            $migrator = new Migrator($pdo, $directory);

            self::assertSame([$migrationName], $migrator->run());
            self::assertSame([], $migrator->run());
            self::assertSame(6, $this->tableCount($pdo, array_values($tables)));
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    private function connection(): PDO
    {
        $dsn = getenv('TEST_DB_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        return new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** @param list<string> $tables */
    private function tableCount(PDO $pdo, array $tables): int
    {
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
        );
        $statement->execute($tables);

        return (int) $statement->fetchColumn();
    }
}
