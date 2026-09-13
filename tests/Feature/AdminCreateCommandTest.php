<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class AdminCreateCommandTest extends TestCase
{
    public function testCommandHasNoDefaultCredentialsAndRejectsPasswordArguments(): void
    {
        $path = dirname(__DIR__, 2) . '/bin/create-admin.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('STDIN', $source);
        self::assertStringContainsString("str_starts_with(\$argument, '--password')", $source);
        self::assertStringNotContainsString("'password:'", $source);
        self::assertStringNotContainsString('admin123', strtolower($source));
        self::assertStringNotContainsString('password123', strtolower($source));
    }
}
