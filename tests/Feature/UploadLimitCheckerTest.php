<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class UploadLimitCheckerTest extends TestCase
{
    public function testCheckerWarnsWhenTheConfiguredLimitExceedsPhpCapacityWithoutPrintingSecrets(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-check-limit-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'MEDIA_PRIVATE_ROOT' => $root,
            'MEDIA_MAX_UPLOAD_BYTES' => '52428800',
            'FFPROBE_BINARY' => $root . '/missing-ffprobe',
            'GEMINI_API_KEY' => 'checker-secret-sentinel',
        ]);
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=12M', dirname(__DIR__, 2) . '/bin/check-requirements.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true]
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(0, $exit, (string) $stderr . (string) $stdout);
            self::assertSame('', $stderr);
            self::assertStringContainsString('[WARN] Capacidade efetiva de upload', (string) $stdout);
            self::assertStringContainsString('MEDIA_MAX_UPLOAD_BYTES excede', (string) $stdout);
            self::assertStringNotContainsString('checker-secret-sentinel', (string) $stdout . (string) $stderr);
        } finally {
            @rmdir($root);
        }
    }

    public function testCheckerDoesNotReportAnAppLimitWarningWhenItFitsPhpCapacity(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-check-fitting-limit-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'MEDIA_PRIVATE_ROOT' => $root,
            'MEDIA_MAX_UPLOAD_BYTES' => '1048576',
            'FFPROBE_BINARY' => $root . '/missing-ffprobe',
        ]);
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=12M', dirname(__DIR__, 2) . '/bin/check-requirements.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true]
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(0, $exit, (string) $stderr . (string) $stdout);
            self::assertSame('', $stderr);
            self::assertStringNotContainsString('MEDIA_MAX_UPLOAD_BYTES excede', (string) $stdout);
        } finally {
            @rmdir($root);
        }
    }
}
