<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SafePhase5TestDatabase;

final class MediaMigrationRecoveryTest extends TestCase
{
    private const PROFILE = '202609040004_create_clip_render_profiles.sql';
    private const DIMENSIONS = '202609060044_upgrade_render_profile_dimensions.sql';
    private const LIBRARY = '202609060045_create_editor_library.sql';
    private const THUMBNAILS = '202609060050_create_thumbnail_studio.sql';
    private const PUBLICATIONS = '202609060051_create_publication_preparations.sql';
    private const MIGRATIONS = [self::DIMENSIONS, self::LIBRARY, self::THUMBNAILS, self::PUBLICATIONS];
    private const TABLES = ['publication_events', 'publication_preparations', 'clip_thumbnails', 'clip_thumbnail_sets', 'user_brand_logos', 'user_brand_kits', 'user_editor_templates'];

    private PDO $pdo;
    private string $directory;
    private bool $mutated = false;

    protected function setUp(): void
    {
        if (getenv('ALLOW_SCHEMA_RECOVERY_TEST') !== '1') {
            self::markTestSkipped('Set ALLOW_SCHEMA_RECOVERY_TEST=1 for the isolated schema gate.');
        }
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame('cliplab_phase5_test', $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        foreach (self::TABLES as $table) {
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "Recovery requires empty {$table}.");
        }
        $this->directory = sys_get_temp_dir() . '/media-schema-recovery-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        foreach ([self::PROFILE, ...self::MIGRATIONS] as $migration) {
            self::assertTrue(copy(dirname(__DIR__, 2) . '/database/migrations/' . $migration, $this->directory . '/' . $migration));
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        try {
            if ($this->mutated) {
                $this->dropEmptyTables();
                // Restore the original test fixture even when a guard regression fails.
                foreach ([self::LIBRARY, self::THUMBNAILS, self::PUBLICATIONS] as $migration) {
                    $this->pdo->exec((string) file_get_contents($this->directory . '/' . $migration));
                }
                foreach ([self::PROFILE, ...self::MIGRATIONS] as $migration) {
                    $this->pdo->prepare('INSERT IGNORE INTO migrations (migration) VALUES (?)')->execute([$migration]);
                }
            }
        } finally {
            foreach ([self::PROFILE, ...self::MIGRATIONS] as $migration) {
                unlink($this->directory . '/' . $migration);
            }
            rmdir($this->directory);
        }
    }

    public function testUpgradedProfileCanRecoverItsOriginalMissingLedgerEntry(): void
    {
        $this->forget([self::PROFILE]);
        self::assertSame([self::PROFILE], (new Migrator($this->pdo, $this->directory))->run());
        self::assertSame([], (new Migrator($this->pdo, $this->directory))->run());
    }

    public function testFreshMultiTableMigrationsAndAllSurvivingTablesRecoverDeterministically(): void
    {
        $this->mutated = true;
        $this->dropEmptyTables();
        $this->forget(self::MIGRATIONS);
        $migrator = new Migrator($this->pdo, $this->directory);
        self::assertSame(self::MIGRATIONS, $migrator->run());
        self::assertSame([], $migrator->run());
        $this->forget(self::MIGRATIONS);
        self::assertSame(self::MIGRATIONS, $migrator->run());
        self::assertSame([], $migrator->run());
    }

    public function testEquivalentInnoDbNoActionDeleteRuleRecoversOnBothDatabaseEngines(): void
    {
        $this->forget([self::LIBRARY]);
        $this->pdo->exec('ALTER TABLE user_brand_logos DROP FOREIGN KEY fk_brand_logos_owner');
        $this->pdo->exec('ALTER TABLE user_brand_logos ADD CONSTRAINT fk_brand_logos_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE NO ACTION');
        self::assertSame([self::LIBRARY], (new Migrator($this->pdo, $this->directory))->run());
    }

    /** @dataProvider corruptSurvivors */
    public function testCorruptSurvivorCannotGetALedgerEntry(string $migration, string $mutation): void
    {
        $this->forget([$migration]);
        $this->pdo->exec($mutation);
        try {
            (new Migrator($this->pdo, $this->directory))->run();
            self::fail('Corrupt table must fail its migration postcondition.');
        } catch (RuntimeException $error) {
            self::assertSame('Migration schema postcondition failed.', $error->getMessage());
            $query = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
            $query->execute([$migration]);
            self::assertSame(0, (int) $query->fetchColumn());
        }
    }

    public static function corruptSurvivors(): array
    {
        return [
            'template required name' => [self::LIBRARY, 'ALTER TABLE user_editor_templates MODIFY name VARCHAR(80) NULL'],
            'template unicode text' => [self::LIBRARY, 'ALTER TABLE user_editor_templates MODIFY name VARCHAR(80) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL'],
            'brand kit JSON validity' => [self::LIBRARY, 'ALTER TABLE user_brand_kits MODIFY favorites_json LONGTEXT NOT NULL'],
            'logo ownership deletion' => [self::LIBRARY, 'ALTER TABLE user_brand_logos DROP FOREIGN KEY fk_brand_logos_owner; ALTER TABLE user_brand_logos ADD CONSTRAINT fk_brand_logos_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'],
            'template update timestamp' => [self::LIBRARY, 'ALTER TABLE user_editor_templates MODIFY updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
            'candidate count default' => [self::THUMBNAILS, 'ALTER TABLE clip_thumbnail_sets MODIFY candidate_count INT UNSIGNED NOT NULL DEFAULT 1'],
            'request collation' => [self::THUMBNAILS, 'ALTER TABLE clip_thumbnail_sets MODIFY request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL'],
            'thumbnail decimal precision' => [self::THUMBNAILS, 'ALTER TABLE clip_thumbnails MODIFY offset_seconds DECIMAL(9,2) NOT NULL'],
            'thumbnail object uniqueness' => [self::THUMBNAILS, 'ALTER TABLE clip_thumbnails DROP INDEX uq_thumbnail_object, ADD KEY uq_thumbnail_object (object_key)'],
            'thumbnail parent delete rule' => [self::THUMBNAILS, 'ALTER TABLE clip_thumbnails DROP FOREIGN KEY clip_thumbnails_ibfk_1; ALTER TABLE clip_thumbnails ADD CONSTRAINT clip_thumbnails_ibfk_1 FOREIGN KEY (set_id) REFERENCES clip_thumbnail_sets(id) ON DELETE CASCADE'],
            'publication initial version' => [self::PUBLICATIONS, 'ALTER TABLE publication_preparations MODIFY version INT UNSIGNED NOT NULL DEFAULT 0'],
            'publication event uniqueness' => [self::PUBLICATIONS, 'ALTER TABLE publication_events DROP INDEX uq_publication_event_version, ADD KEY uq_publication_event_version (publication_id,version)'],
            'event JSON validity' => [self::PUBLICATIONS, 'ALTER TABLE publication_events MODIFY snapshot_json LONGTEXT NOT NULL'],
        ];
    }

    private function forget(array $migrations): void
    {
        $this->mutated = true;
        foreach ($migrations as $migration) {
            $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migration]);
        }
    }

    private function dropEmptyTables(): void
    {
        foreach (self::TABLES as $table) {
            // No FK disabling: a foreign dependency must stop this isolated fixture reset.
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}
