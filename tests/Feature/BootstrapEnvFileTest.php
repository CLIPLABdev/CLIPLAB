<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class BootstrapEnvFileTest extends TestCase
{
    public function testAbsentOverrideLoadsOnlyTheDefaultRootEnvFile(): void
    {
        $result = $this->runBootstrap(null);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame(dirname(__DIR__, 2) . '/.env', $result['loaded']);
    }

    public function testExplicitOverrideLoadsOnlyTheSelectedFile(): void
    {
        $selected = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-selected-env-' . bin2hex(random_bytes(6));
        $result = $this->runBootstrap($selected);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame($selected, $result['loaded']);
    }

    public function testEmptyOverrideDisablesEnvironmentFileLoading(): void
    {
        $result = $this->runBootstrap('');
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertNull($result['loaded']);
    }

    /** @return array{exit:int,stdout:string,stderr:string,loaded:?string} */
    private function runBootstrap(?string $override): array
    {
        $prepend = tempnam(sys_get_temp_dir(), 'clipforge-bootstrap-prepend-');
        $capture = tempnam(sys_get_temp_dir(), 'clipforge-bootstrap-capture-');
        self::assertNotFalse($prepend);
        self::assertNotFalse($capture);
        unlink($capture);
        file_put_contents($prepend, <<<'PHP'
<?php
namespace App\Core {
    final class Env {
        public static function load(string $path): void {
            file_put_contents((string) getenv('BOOTSTRAP_ENV_CAPTURE'), $path);
        }
        public static function get(string $key, ?string $default = null): ?string { return $default; }
    }
    final class ErrorHandler { public function register(bool $debug): void {} }
}
PHP
        );

        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        unset($environment['APP_ENV_FILE']);
        $environment['BOOTSTRAP_ENV_CAPTURE'] = $capture;
        if ($override !== null && $override !== '') {
            $environment['APP_ENV_FILE'] = $override;
        }

        $script = dirname(__DIR__, 2) . '/bootstrap/app.php';
        $runner = null;
        if ($override === '') {
            $runner = tempnam(sys_get_temp_dir(), 'clipforge-bootstrap-runner-');
            self::assertNotFalse($runner);
            file_put_contents(
                $runner,
                '<?php putenv("APP_ENV_FILE="); require ' . var_export($script, true) . ';'
            );
            $script = $runner;
        }

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, $script],
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
            $loaded = is_file($capture) ? file_get_contents($capture) : null;

            return [
                'exit' => $exit,
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
                'loaded' => is_string($loaded) ? $loaded : null,
            ];
        } finally {
            @unlink($prepend);
            @unlink($capture);
            if (is_string($runner)) {
                @unlink($runner);
            }
        }
    }
}
