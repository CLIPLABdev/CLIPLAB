<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/migrations-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testUnmappedMigrationsKeepRunningOnlyOnceWithoutStructuralIntrospection(): void
    {
        file_put_contents($this->directory . '/001.sql', 'CREATE TABLE sample (id INTEGER PRIMARY KEY);');
        $migrator = new Migrator(new PDO('sqlite::memory:'), $this->directory);

        self::assertSame(['001.sql'], $migrator->run());
        self::assertSame([], $migrator->run());
    }

    public function testMySqlDisabledCheckCannotSatisfyStructuralPostcondition(): void
    {
        self::assertFalse(Migrator::checkConstraintMetadataMatches('amount > 0', 'amount > 0', 'NO'));
        self::assertTrue(Migrator::checkConstraintMetadataMatches('(`amount` > 0)', 'amount > 0', 'YES'));
        self::assertTrue(Migrator::checkConstraintMetadataMatches('(`amount` > 0)', 'amount > 0', null));
    }

    public function testCheckComparisonPreservesMeaningfulBooleanGrouping(): void
    {
        $expected = "mode = 'auto' AND detector IS NOT NULL AND detector = 'v1' OR mode <> 'auto' AND detector IS NULL";
        $regrouped = "mode = 'auto' AND detector IS NOT NULL AND (detector = 'v1' OR mode <> 'auto') AND detector IS NULL";
        $mysqlFormatting = "((`mode` = _utf8mb4'auto') AND (`detector` IS NOT NULL) AND (`detector` = _utf8mb4'v1')) OR ((`mode` <> _utf8mb4'auto') AND (`detector` IS NULL))";

        self::assertFalse(Migrator::checkConstraintMetadataMatches($regrouped, $expected, 'YES'));
        self::assertTrue(Migrator::checkConstraintMetadataMatches($mysqlFormatting, $expected, 'YES'));
    }

    public function testMySql84CheckClauseWithCharsetPrefixMatchesExpectedSemantics(): void
    {
        $actual = "((`aspect_ratio` = _utf8mb4'original') and (`reframe_mode` = _utf8mb4'original') and (`output_width` is null) and (`output_height` is null)) or ((`reframe_mode` in (_utf8mb4'center',_utf8mb4'manual',_utf8mb4'auto')) and (`output_width` is not null) and (`output_height` is not null) and (((`aspect_ratio` = _utf8mb4'9:16') and (((`output_width` = 720) and (`output_height` = 1280)) or ((`output_width` = 1080) and (`output_height` = 1920)))) or ((`aspect_ratio` = _utf8mb4'1:1') and (((`output_width` = 720) and (`output_height` = 720)) or ((`output_width` = 1080) and (`output_height` = 1080)))) or ((`aspect_ratio` = _utf8mb4'16:9') and (((`output_width` = 1280) and (`output_height` = 720)) or ((`output_width` = 1920) and (`output_height` = 1080)))) or ((`aspect_ratio` = _utf8mb4'4:5') and (((`output_width` = 720) and (`output_height` = 900)) or ((`output_width` = 1080) and (`output_height` = 1350)))))))";
        $expected = "(aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL) OR (reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND ((aspect_ratio = '9:16' AND ((output_width = 720 AND output_height = 1280) OR (output_width = 1080 AND output_height = 1920))) OR (aspect_ratio = '1:1' AND ((output_width = 720 AND output_height = 720) OR (output_width = 1080 AND output_height = 1080))) OR (aspect_ratio = '16:9' AND ((output_width = 1280 AND output_height = 720) OR (output_width = 1920 AND output_height = 1080))) OR (aspect_ratio = '4:5' AND ((output_width = 720 AND output_height = 900) OR (output_width = 1080 AND output_height = 1350)))))";

        self::assertTrue(Migrator::checkConstraintMetadataMatches($actual, $expected, 'YES'));
    }

    public function testSchemaPostconditionExceptionDropsDatabaseExceptionChain(): void
    {
        file_put_contents(
            $this->directory . '/202609040004_create_clip_render_profiles.sql',
            'CREATE TABLE clip_render_profiles (id INTEGER PRIMARY KEY);'
        );
        $migrator = new Migrator(new PDO('sqlite::memory:'), $this->directory);

        try {
            $migrator->run();
            self::fail('Mapped migration must fail structural verification on SQLite.');
        } catch (RuntimeException $exception) {
            self::assertSame('Migration schema postcondition failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
