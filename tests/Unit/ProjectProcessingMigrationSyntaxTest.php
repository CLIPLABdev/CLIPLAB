<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProjectProcessingMigrationSyntaxTest extends TestCase
{
    public function testAlterStatementsAvoidVendorSpecificIfNotExistsGuards(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/202609030002_create_project_processing_tables.sql');
        self::assertNotFalse($sql);
        self::assertDoesNotMatchRegularExpression(
            '/ADD\\s+(?:COLUMN|(?:UNIQUE\\s+)?INDEX)\\s+IF\\s+NOT\\s+EXISTS/i',
            $sql,
            'ALTER recovery belongs in Migrator so the SQL remains valid on MySQL 8.'
        );
    }
}
