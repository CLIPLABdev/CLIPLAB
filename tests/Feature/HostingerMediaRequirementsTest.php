<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class HostingerMediaRequirementsTest extends TestCase
{
    public function testTrackedProductionArtifactContainsVendoredMediaPipeWithoutRuntimeNodeCachesOrCdn(): void
    {
        $process = proc_open(
            ['git', 'ls-files', '-z'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        $tracked = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);
        $files = array_values(array_filter(explode("\0", (string) $tracked), static fn (string $path): bool => $path !== ''));

        self::assertContains('public/assets/vendor/mediapipe-tasks-vision-1.0.1/manifest.json', $files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('~(?:^|/)(?:node_modules|\.npm|\.pnpm-store|npm-cache|pnpm-cache|yarn-cache)(?:/|$)~i', $file);
        }

        $sources = '';
        foreach (['reframe-editor.js', 'reframe-tracker.js', 'reframe-worker.js'] as $file) {
            $sources .= (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/' . $file);
        }
        self::assertStringNotContainsString('cdn.', strtolower($sources));
        self::assertStringNotContainsString('unpkg.', strtolower($sources));
    }

    public function testRunbooksDescribeTheSmartReframeHostingerDeploymentAndRollbackContract(): void
    {
        $documentation = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md')
            . (string) file_get_contents(dirname(__DIR__, 2) . '/docs/HOSTINGER.md');

        foreach ([
            'MySQL 8.0.16+', 'MariaDB 10.4+', 'CHECK',
            'APP_ENV_FILE', '202609040004', '202609040005', '202609040006',
            '.mjs', '.wasm', '.tflite', 'processamento no dispositivo',
            'sem JavaScript', 'node_modules', 'storage privado compartilhado',
            'profiles', 'ledger',
        ] as $needle) {
            self::assertStringContainsString($needle, $documentation);
        }
        self::assertMatchesRegularExpression('/backup[\s\S]+202609040004[\s\S]+check-requirements[\s\S]+cron[\s\S]+smoke/i', $documentation);
        self::assertMatchesRegularExpression('/pare o cron[\s\S]+revert/i', $documentation);
    }

    public function testUnavailableLocalProcessorIsOnlyAWarnWithVpsAction(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-check-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'APP_ENV_FILE' => sys_get_temp_dir() . '/cliplab-no-env-file',
            'DB_DSN' => (string) getenv('TEST_DB_DSN'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'MEDIA_PRIVATE_ROOT' => $root,
            'FFPROBE_BINARY' => $root . '/missing-ffprobe',
        ]);
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-requirements.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        @rmdir($root);

        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Extensão fileinfo', (string) $stdout);
        self::assertStringContainsString('Extensão curl', (string) $stdout);
        self::assertStringContainsString('upload_max_filesize', (string) $stdout);
        self::assertStringContainsString('post_max_size', (string) $stdout);
        self::assertStringContainsString('Armazenamento privado gravável', (string) $stdout);
        self::assertStringContainsString('PHP CLI', (string) $stdout);
        self::assertStringContainsString('proc_open', (string) $stdout);
        self::assertStringContainsString('[WARN] FFprobe', (string) $stdout);
        self::assertStringContainsString('configure o mesmo artefato PHP em um worker VPS', (string) $stdout);
        self::assertStringNotContainsString('RemoteMediaProcessor', (string) $stdout);
    }

    public function testCheckerPassesThePrivateMediaRootToTheProcessRunner(): void
    {
        $root = sys_get_temp_dir() . '/cliplab-check-runner-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $capture = tempnam(sys_get_temp_dir(), 'checker-runner-root-');
        $prepend = tempnam(sys_get_temp_dir(), 'checker-runner-prepend-');
        self::assertNotFalse($capture);
        self::assertNotFalse($prepend);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($prepend, "<?php\nnamespace { require {$autoload}; }\nnamespace App\\Process { final class ProcessRunner { public function __construct(array \$allowedBinaries, string \$temporaryDirectory) { file_put_contents((string) getenv('RUNNER_ROOT_CAPTURE'), \$temporaryDirectory); } public function run(array \$command, int \$timeoutSeconds, int \$outputLimitBytes): ProcessResult { return new ProcessResult(1, '', ''); } } }\n");
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'APP_ENV_FILE' => sys_get_temp_dir() . '/cliplab-no-env-file',
            'DB_DSN' => (string) getenv('TEST_DB_DSN'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'MEDIA_PRIVATE_ROOT' => $root,
            'FFPROBE_BINARY' => 'ffprobe',
            'RUNNER_ROOT_CAPTURE' => $capture,
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
            $exit = proc_close($process);

            self::assertSame(0, $exit, (string) $stderr . (string) $stdout);
            self::assertSame('', $stderr);
            self::assertSame(realpath($root), file_get_contents($capture));
        } finally {
            @unlink($prepend);
            @unlink($capture);
            @rmdir($root);
        }
    }
}
