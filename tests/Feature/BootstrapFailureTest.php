<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class BootstrapFailureTest extends TestCase
{
    public function testBootstrapFailureDoesNotExposeItsMessageOrPath(): void
    {
        $directory = sys_get_temp_dir() . '/clipforge-bootstrap-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($directory . '/public', 0777, true));
        $entryPoint = $directory . '/public/index.php';
        self::assertTrue(copy(dirname(__DIR__, 2) . '/public/index.php', $entryPoint));

        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entryPoint);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);

            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(1, $exitCode);
            self::assertStringContainsString('Erro temporário. Tente novamente.', $output);
            self::assertStringNotContainsString('bootstrap/app.php', $output);
            self::assertStringNotContainsString($directory, $output);
        } finally {
            unlink($entryPoint);
            rmdir($directory . '/public');
            rmdir($directory);
        }
    }
}
