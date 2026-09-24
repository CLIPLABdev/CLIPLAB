<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class FfmpegRequirementsTest extends TestCase
{
    public function testCheckerRejectsUnrelatedWindowsExecutablesWithoutPrintingTheirPath(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Windows executable basename validation only applies on Windows.');
        }
        $systemRoot = getenv('SystemRoot');
        $cmd = is_string($systemRoot) ? $systemRoot . '\\System32\\cmd.exe' : '';
        if ($cmd === '' || !is_file($cmd)) {
            self::markTestSkipped('cmd.exe is unavailable for the Windows boundary regression.');
        }
        $root = sys_get_temp_dir() . '/cliplab-ffmpeg-name-check-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));

        try {
            $result = $this->runChecker([
                'MEDIA_PRIVATE_ROOT' => $root,
                'FFPROBE_BINARY' => $cmd,
                'FFMPEG_BINARY' => $cmd,
            ]);

            self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertSame('', $result['stderr']);
            self::assertStringContainsString('[WARN] FFprobe', $result['stdout']);
            self::assertStringContainsString('[WARN] FFmpeg', $result['stdout']);
            self::assertStringContainsString('worker VPS', $result['stdout']);
            self::assertStringNotContainsString($cmd, $result['stdout'] . $result['stderr']);
        } finally {
            @rmdir($root);
        }
    }

    public function testCheckerAcceptsExpectedAbsoluteBinaryNamesWithoutExecutingThem(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-ffmpeg-absolute-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));
        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        $ffprobe = $root . '/ffprobe' . $suffix;
        $ffmpeg = $root . '/ffmpeg' . $suffix;
        self::assertNotFalse(file_put_contents($ffprobe, 'ffprobe-must-not-run'));
        self::assertNotFalse(file_put_contents($ffmpeg, 'ffmpeg-must-not-run'));
        chmod($ffprobe, 0700);
        chmod($ffmpeg, 0700);

        try {
            $result = $this->runChecker([
                'MEDIA_PRIVATE_ROOT' => $root,
                'FFPROBE_BINARY' => $ffprobe,
                'FFMPEG_BINARY' => $ffmpeg,
            ]);

            self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertStringContainsString('[OK] FFprobe', $result['stdout']);
            self::assertStringContainsString('[OK] FFmpeg', $result['stdout']);
            self::assertStringNotContainsString($ffprobe, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString($ffmpeg, $result['stdout'] . $result['stderr']);
            self::assertSame('ffprobe-must-not-run', file_get_contents($ffprobe));
            self::assertSame('ffmpeg-must-not-run', file_get_contents($ffmpeg));
        } finally {
            @unlink($ffprobe);
            @unlink($ffmpeg);
            @rmdir($root);
        }
    }

    public function testCheckerResolvesExpectedBareNamesFromPathWithoutExecutingThem(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-ffmpeg-path-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));
        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        $ffprobe = $root . '/ffprobe' . $suffix;
        $ffmpeg = $root . '/ffmpeg' . $suffix;
        self::assertNotFalse(file_put_contents($ffprobe, 'ffprobe-path-must-not-run'));
        self::assertNotFalse(file_put_contents($ffmpeg, 'ffmpeg-path-must-not-run'));
        chmod($ffprobe, 0700);
        chmod($ffmpeg, 0700);

        try {
            $result = $this->runChecker([
                'MEDIA_PRIVATE_ROOT' => $root,
                'FFPROBE_BINARY' => 'ffprobe',
                'FFMPEG_BINARY' => 'ffmpeg',
                'PATH' => $root,
            ]);

            self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertStringContainsString('[OK] FFprobe', $result['stdout']);
            self::assertStringContainsString('[OK] FFmpeg', $result['stdout']);
            self::assertSame('ffprobe-path-must-not-run', file_get_contents($ffprobe));
            self::assertSame('ffmpeg-path-must-not-run', file_get_contents($ffmpeg));
        } finally {
            @unlink($ffprobe);
            @unlink($ffmpeg);
            @rmdir($root);
        }
    }

    public function testCheckerInspectsRenderCapacityWithoutExecutingBinariesOrPrintingPaths(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-ffmpeg-check-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));
        $ffprobe = $root . '/private-ffprobe-sentinel';
        $ffmpeg = $root . '/private-ffmpeg-sentinel';

        try {
            $result = $this->runChecker([
                'MEDIA_PRIVATE_ROOT' => $root,
                'FFPROBE_BINARY' => $ffprobe,
                'FFMPEG_BINARY' => $ffmpeg,
                'QUEUE_LEASE_SECONDS' => '300',
                'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
                'RENDER_TIMEOUT_SECONDS' => '240',
            ]);

            self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertSame('', $result['stderr']);
            self::assertStringContainsString('[WARN] FFprobe', $result['stdout']);
            self::assertStringContainsString('[WARN] FFmpeg', $result['stdout']);
            self::assertStringContainsString('proc_open', $result['stdout']);
            self::assertStringContainsString('Armazenamento privado legível', $result['stdout']);
            self::assertStringContainsString('Diretório temporário privado', $result['stdout']);
            self::assertStringContainsString('Lease do worker', $result['stdout']);
            self::assertStringNotContainsString($ffprobe, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString($ffmpeg, $result['stdout'] . $result['stderr']);

            $source = file_get_contents(dirname(__DIR__, 2) . '/bin/check-requirements.php');
            self::assertIsString($source);
            self::assertStringNotContainsString('->run(', $source);
        } finally {
            @rmdir($root);
        }
    }

    public function testCheckerFailsWhenLeaseDoesNotCoverLargestOperationBudget(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-lease-check-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));

        try {
            $result = $this->runChecker([
                'MEDIA_PRIVATE_ROOT' => $root,
                'FFPROBE_BINARY' => $root . '/missing-ffprobe',
                'FFMPEG_BINARY' => $root . '/missing-ffmpeg',
                'QUEUE_LEASE_SECONDS' => '300',
                'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
                'RENDER_TIMEOUT_SECONDS' => '400',
            ]);

            self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertSame('', $result['stderr']);
            self::assertStringContainsString('[FALHA] Lease do worker', $result['stdout']);
            self::assertStringContainsString('30 segundos', $result['stdout']);
        } finally {
            @rmdir($root);
        }
    }

    /** @param array<string,string> $overrides @return array{exit:int,stdout:string,stderr:string} */
    private function runChecker(array $overrides): array
    {
        $prepend = tempnam(sys_get_temp_dir(), 'ffmpeg-requirements-prepend-');
        self::assertNotFalse($prepend);
        file_put_contents($prepend, <<<'PHP'
<?php
putenv('APP_ENV_FILE=');
define('CHECK_REQUIREMENTS_PROJECT_ROOT', (string) getenv('CHECKER_PROJECT_ROOT'));
define('CHECK_REQUIREMENTS_DATABASE_CAPABILITY', [
    'driver' => 'mysql',
    'version' => '10.4.32-MariaDB',
    'check_constraints' => true,
]);
PHP
        );
        $environment = getenv();
        if (DIRECTORY_SEPARATOR === '\\' && array_key_exists('PATH', $overrides) && is_array($environment)) {
            unset($environment['Path'], $environment['PATH']);
        }
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'APP_ENV_FILE' => '',
            'CHECKER_PROJECT_ROOT' => dirname(__DIR__, 2),
        ], $overrides, [
            'GEMINI_API_KEY' => 'configured-for-checker',
            'GEMINI_MODEL' => 'configured-for-checker',
        ]);
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, dirname(__DIR__, 2) . '/bin/check-requirements.php'],
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

            return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
        } finally {
            @unlink($prepend);
        }
    }
}
