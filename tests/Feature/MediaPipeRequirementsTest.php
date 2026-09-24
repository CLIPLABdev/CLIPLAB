<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class MediaPipeRequirementsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryRoots) as $root) {
            $this->removeTree($root);
        }
    }

    public function testValidOfflineRequirementsAreSanitizedAndKeepTheExistingVocabulary(): void
    {
        [$root, $storage] = $this->fixture();
        $result = $this->runChecker($root, $storage);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertSame('', $result['stderr']);
        foreach (['Assets MediaPipe', 'Configuração Smart Reframe', 'CSP do worker', 'MIME MediaPipe', 'Banco SQL com CHECK', 'Lease do worker'] as $name) {
            self::assertStringContainsString('[OK] ' . $name, $result['stdout']);
        }
        self::assertStringContainsString('[WARN]', $result['stdout']);
        self::assertStringNotContainsString('[AVISO]', $result['stdout']);
        self::assertStringNotContainsString($storage, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('private/object-key-sentinel.mp4', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('checker-secret-sentinel', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('sha256', strtolower($result['stdout'] . $result['stderr']));
        self::assertDoesNotMatchRegularExpression('/\b[a-f0-9]{64}\b/i', $result['stdout'] . $result['stderr']);
    }

    public function testCorruptManifestFailsWithoutPrintingPathOrHash(): void
    {
        [$root, $storage] = $this->fixture();
        file_put_contents($root . '/public/assets/vendor/mediapipe-tasks-vision-1.0.1/vision_bundle.mjs', 'corrupt', FILE_APPEND);
        $result = $this->runChecker($root, $storage);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FALHA] Assets MediaPipe', $result['stdout']);
        self::assertStringNotContainsString($root, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('sha256', strtolower($result['stdout'] . $result['stderr']));
    }

    /** @dataProvider invalidReframeConfiguration */
    public function testRejectsUnsupportedVersionAndLimits(array $overrides): void
    {
        [$root, $storage] = $this->fixture();
        $result = $this->runChecker($root, $storage, $overrides);

        self::assertSame(1, $result['exit'], $result['stdout']);
        self::assertStringContainsString('[FALHA] Configuração Smart Reframe', $result['stdout']);
    }

    /** @return iterable<string, array{array<string,string>}> */
    public function invalidReframeConfiguration(): iterable
    {
        yield 'version mismatch' => [['MEDIAPIPE_ASSET_VERSION' => '1.0.0']];
        yield 'keyframes below exact contract' => [['REFRAME_MAX_KEYFRAMES' => '31']];
        yield 'duration below range' => [['REFRAME_MAX_DURATION_SECONDS' => '0']];
        yield 'duration above range' => [['REFRAME_MAX_DURATION_SECONDS' => '181']];
        yield 'frames below range' => [['REFRAME_PREVIEW_MAX_FRAMES' => '1']];
        yield 'frames above range' => [['REFRAME_PREVIEW_MAX_FRAMES' => '181']];
        yield 'edge below range' => [['REFRAME_PREVIEW_MAX_EDGE' => '63']];
        yield 'edge above range' => [['REFRAME_PREVIEW_MAX_EDGE' => '321']];
    }

    public function testRejectsMissingWorkerCsp(): void
    {
        [$root, $storage] = $this->fixture();
        $path = $root . '/app/Middleware/SecurityHeadersMiddleware.php';
        file_put_contents($path, str_replace("worker-src 'self'", "worker-src 'none'", (string) file_get_contents($path)));
        $result = $this->runChecker($root, $storage);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FALHA] CSP do worker', $result['stdout']);
    }

    /** @dataProvider apacheEntryPoints */
    public function testRejectsMissingWorkerResponseCspInEitherApacheEntryPoint(string $relativeFile): void
    {
        [$root, $storage] = $this->fixture();
        $path = $root . '/' . $relativeFile;
        $contents = (string) file_get_contents($path);
        $withoutWorkerPolicy = preg_replace(
            '/^\s*Header always set Content-Security-Policy .+\R?/m',
            '',
            $contents
        );
        self::assertIsString($withoutWorkerPolicy);
        self::assertNotSame($contents, $withoutWorkerPolicy);
        file_put_contents($path, $withoutWorkerPolicy);

        $result = $this->runChecker($root, $storage);

        self::assertSame(1, $result['exit'], $result['stdout']);
        self::assertStringContainsString('[FALHA] CSP do worker', $result['stdout']);
    }

    /** @return iterable<string, array{string}> */
    public function apacheEntryPoints(): iterable
    {
        yield 'project root fallback' => ['.htaccess'];
        yield 'public document root' => ['public/.htaccess'];
    }

    /** @dataProvider requiredMimeDirectives */
    public function testRejectsMissingMimeDirectiveInEitherApacheEntryPoint(string $relativeFile, string $directive): void
    {
        [$root, $storage] = $this->fixture();
        $path = $root . '/' . $relativeFile;
        file_put_contents($path, str_replace($directive, '', (string) file_get_contents($path)));
        $result = $this->runChecker($root, $storage);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FALHA] MIME MediaPipe', $result['stdout']);
    }

    /** @return iterable<string, array{string,string}> */
    public function requiredMimeDirectives(): iterable
    {
        foreach (['.htaccess', 'public/.htaccess'] as $file) {
            yield $file . ' mjs' => [$file, 'AddType application/javascript .mjs'];
            yield $file . ' wasm' => [$file, 'AddType application/wasm .wasm'];
            yield $file . ' tflite' => [$file, 'AddType application/octet-stream .tflite'];
        }
    }

    /** @dataProvider databaseCapabilities */
    public function testAcceptsOnlySupportedDatabaseCapabilities(string $driver, string $version, ?bool $checks, bool $valid): void
    {
        [$root, $storage] = $this->fixture();
        $result = $this->runChecker($root, $storage, [], $driver, $version, $checks);

        self::assertSame($valid ? 0 : 1, $result['exit'], $result['stdout']);
        self::assertStringContainsString(($valid ? '[OK]' : '[FALHA]') . ' Banco SQL com CHECK', $result['stdout']);
        self::assertStringNotContainsString($version, $result['stdout'] . $result['stderr']);
    }

    /** @return iterable<string, array{string,string,?bool,bool}> */
    public function databaseCapabilities(): iterable
    {
        yield 'minimum MySQL' => ['mysql', '8.0.16', null, true];
        yield 'old MySQL' => ['mysql', '8.0.15', null, false];
        yield 'minimum MariaDB with checks' => ['mysql', '10.4.0-MariaDB', true, true];
        yield 'old MariaDB' => ['mysql', '10.3.99-MariaDB', true, false];
        yield 'MariaDB checks disabled' => ['mysql', '10.4.32-MariaDB', false, false];
        yield 'wrong PDO engine' => ['sqlite', '3.42.0', null, false];
    }

    public function testLeaseIncludesSequentialDownloadAndProbeBudget(): void
    {
        [$root, $storage] = $this->fixture();
        $result = $this->runChecker($root, $storage, [
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '10',
            'RENDER_TIMEOUT_SECONDS' => '5',
            'MEDIA_DOWNLOAD_TIMEOUT_SECONDS' => '120',
            'PROCESS_TIMEOUT_SECONDS' => '60',
            'QUEUE_LEASE_SECONDS' => '209',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FALHA] Lease do worker', $result['stdout']);
        self::assertStringContainsString('30 segundos', $result['stdout']);
    }

    public function testMissingPrivateRootIsRejectedWithoutCreatingIt(): void
    {
        [$root] = $this->fixture();
        $missingStorage = $root . '/missing-private-root';

        $result = $this->runChecker($root, $missingStorage);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertDirectoryDoesNotExist($missingStorage);
        self::assertStringContainsString('[FALHA] Armazenamento privado legível', $result['stdout']);
        self::assertStringNotContainsString($missingStorage, $result['stdout'] . $result['stderr']);
    }

    public function testExistingPrivateRootAndSentinelRemainUntouched(): void
    {
        [$root, $storage] = $this->fixture();
        $sentinel = $storage . '/sentinel.bin';
        file_put_contents($sentinel, "checker-storage-sentinel\0contents");
        $before = $this->storageSnapshot($storage);

        $result = $this->runChecker($root, $storage);

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame($before, $this->storageSnapshot($storage));
        self::assertSame("checker-storage-sentinel\0contents", file_get_contents($sentinel));
        self::assertSame([], glob($storage . '/.requirements-*') ?: []);
    }

    public function testLeaseOverflowIsSanitizedEvenWhenDebugIsEnabled(): void
    {
        [$root, $storage] = $this->fixture();
        $result = $this->runChecker($root, $storage, [
            'APP_DEBUG' => 'true',
            'RENDER_TIMEOUT_SECONDS' => (string) PHP_INT_MAX,
            'QUEUE_LEASE_SECONDS' => (string) PHP_INT_MAX,
        ]);
        $expected = '[FALHA] Lease do worker para Gemini/renderização: configuração de timeout inválida';
        $combined = $result['stdout'] . $result['stderr'];

        self::assertSame(1, $result['exit'], $combined);
        self::assertSame('', $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], $expected), $combined);
        self::assertMatchesRegularExpression('/^' . preg_quote($expected, '/') . '\\r?$/m', $result['stdout']);
        foreach ([$root, $storage, 'checker-secret-sentinel', 'check-requirements.php', 'WorkerLeaseBudget.php', 'OverflowException', 'Stack trace', 'exception'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $combined);
        }
    }

    public function testCheckerSourceHasNoExecutionOrProviderBoundary(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/check-requirements.php');
        self::assertStringNotContainsString('->run(', $source);
        self::assertStringNotContainsString('CurlGemini', $source);
        self::assertStringNotContainsString('fetch(', $source);
        self::assertStringContainsString('WorkerLeaseBudget::requiredSeconds(', $source);
        self::assertDoesNotMatchRegularExpression(
            '/\\b(?:mkdir|tempnam|file_put_contents|unlink|touch|chmod|chown|rename|copy|fopen)\\s*\\(/',
            $source
        );
    }

    /** @return array{string,string} */
    private function fixture(): array
    {
        $root = sys_get_temp_dir() . '/cliplab-requirements-' . bin2hex(random_bytes(6));
        $storage = $root . '/private-storage';
        mkdir($storage, 0700, true);
        mkdir($root . '/app/Middleware', 0700, true);
        mkdir($root . '/public', 0700, true);
        copy(dirname(__DIR__, 2) . '/.htaccess', $root . '/.htaccess');
        copy(dirname(__DIR__, 2) . '/public/.htaccess', $root . '/public/.htaccess');
        copy(dirname(__DIR__, 2) . '/app/Middleware/SecurityHeadersMiddleware.php', $root . '/app/Middleware/SecurityHeadersMiddleware.php');
        $this->copyTree(
            dirname(__DIR__, 2) . '/public/assets/vendor/mediapipe-tasks-vision-1.0.1',
            $root . '/public/assets/vendor/mediapipe-tasks-vision-1.0.1'
        );
        $this->temporaryRoots[] = $root;

        return [$root, $storage];
    }

    /** @param array<string,string> $overrides @return array{exit:int,stdout:string,stderr:string} */
    private function runChecker(string $root, string $storage, array $overrides = [], string $driver = 'mysql', string $version = '10.4.32-MariaDB', ?bool $checks = true): array
    {
        $prepend = tempnam(sys_get_temp_dir(), 'cliplab-requirements-prepend-');
        self::assertNotFalse($prepend);
        file_put_contents($prepend, <<<'PHP'
<?php
namespace App\Core {
    final class Logger
    {
        public function __construct(?string $path = null) {}
        public function error(string $message, array $context = []): void {}
    }
}
namespace {
    putenv('APP_ENV_FILE=');
    define('CHECK_REQUIREMENTS_PROJECT_ROOT', (string) getenv('CHECKER_PROJECT_ROOT'));
    define('CHECK_REQUIREMENTS_DATABASE_CAPABILITY', [
        'driver' => (string) getenv('CHECKER_DB_DRIVER'),
        'version' => (string) getenv('CHECKER_DB_VERSION'),
        'check_constraints' => getenv('CHECKER_DB_CHECKS') === 'null' ? null : getenv('CHECKER_DB_CHECKS') === '1',
    ]);
}
PHP
        );
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'APP_ENV_FILE' => '',
            'APP_DEBUG' => 'false',
            'CHECKER_PROJECT_ROOT' => $root,
            'CHECKER_DB_DRIVER' => $driver,
            'CHECKER_DB_VERSION' => $version,
            'CHECKER_DB_CHECKS' => $checks === null ? 'null' : ($checks ? '1' : '0'),
            'MEDIA_PRIVATE_ROOT' => $storage,
            'FFPROBE_BINARY' => 'missing-ffprobe',
            'FFMPEG_BINARY' => 'missing-ffmpeg',
            'GEMINI_API_KEY' => 'checker-secret-sentinel',
            'GEMINI_MODEL' => 'configured-model',
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
            'RENDER_TIMEOUT_SECONDS' => '240',
            'MEDIA_DOWNLOAD_TIMEOUT_SECONDS' => '120',
            'PROCESS_TIMEOUT_SECONDS' => '60',
            'QUEUE_LEASE_SECONDS' => '300',
            'MEDIAPIPE_ASSET_VERSION' => '1.0.1',
            'REFRAME_MAX_KEYFRAMES' => '32',
            'REFRAME_MAX_DURATION_SECONDS' => '90',
            'REFRAME_PREVIEW_MAX_FRAMES' => '180',
            'REFRAME_PREVIEW_MAX_EDGE' => '320',
            'CHECKER_OBJECT_KEY' => 'private/object-key-sentinel.mp4',
        ], $overrides);

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

    private function copyTree(string $source, string $destination): void
    {
        mkdir($destination, 0700, true);
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($from)) $this->copyTree($from, $to); else copy($from, $to);
        }
    }

    /** @return array{entries:list<string>,directory_mode:int,files:array<string,array{size:int,mtime:int,mode:int,sha256:string}>} */
    private function storageSnapshot(string $storage): array
    {
        clearstatcache(true, $storage);
        $entries = array_values(array_filter(
            scandir($storage) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
        $files = [];
        foreach ($entries as $entry) {
            $path = $storage . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $files[$entry] = [
                'size' => (int) filesize($path),
                'mtime' => (int) filemtime($path),
                'mode' => (int) (fileperms($path) & 0777),
                'sha256' => (string) hash_file('sha256', $path),
            ];
        }

        return [
            'entries' => $entries,
            'directory_mode' => (int) (fileperms($storage) & 0777),
            'files' => $files,
        ];
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) return;
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) $this->removeTree($path); else @unlink($path);
        }
        @rmdir($root);
    }
}
