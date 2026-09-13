<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaMigrationGuardTest extends TestCase
{
    /** @dataProvider createMigrations */
    public function testSurvivingTablesMustBeVerifiedBeforeRecordingMigration(string $migration, string $table): void
    {
        $directory = sys_get_temp_dir() . '/media-schema-unit-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $file = $directory . '/' . $migration;
        file_put_contents($file, "CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY);");
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Migration schema postcondition failed.');
            (new Migrator($pdo, $directory))->run();
        } finally {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            unlink($file);
            rmdir($directory);
        }
    }

    public static function createMigrations(): array
    {
        return [
            ['202609060044_upgrade_render_profile_dimensions.sql', 'clip_render_profiles'],
            ['202609060045_create_editor_library.sql', 'user_editor_templates'],
            ['202609060050_create_thumbnail_studio.sql', 'clip_thumbnail_sets'],
            ['202609060051_create_publication_preparations.sql', 'publication_preparations'],
        ];
    }

    public function testMySqlDropCheckTranslationIsRestrictedToTheKnownUpgradeAndCheckSemantics(): void
    {
        $migration = '202609060044_upgrade_render_profile_dimensions.sql';
        $sql = trim((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration));
        $sql = rtrim($sql, ';');
        $mysql = Migrator::statementForServer($migration, $sql, '8.0.36');
        self::assertStringContainsString('DROP CHECK chk_clip_render_profiles_shape', $mysql);
        self::assertStringNotContainsString('DROP CONSTRAINT', $mysql);
        self::assertSame($sql, Migrator::statementForServer($migration, $sql, '5.5.5-10.4.32-MariaDB'));
        self::assertSame($sql, Migrator::statementForServer('unmapped.sql', $sql, '8.0.36'));
        $weakened = str_replace('output_width = 1080', 'output_width >= 1080', $sql);
        self::assertSame($weakened, Migrator::statementForServer($migration, $weakened, '8.0.36'));
        $otherCheck = str_replace('chk_clip_render_profiles_shape', 'other_check', $sql);
        self::assertSame($otherCheck, Migrator::statementForServer($migration, $otherCheck, '8.0.36'));
    }
}
