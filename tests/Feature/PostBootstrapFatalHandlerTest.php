<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PostBootstrapFatalHandlerTest extends TestCase
{
    public function testPostBootstrapFatalUsesOnlyTheCompleteErrorHandler(): void
    {
        $root = sys_get_temp_dir() . '/clipforge-post-bootstrap-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root . '/public', 0777, true));
        self::assertTrue(mkdir($root . '/bootstrap'));
        self::assertTrue(mkdir($root . '/routes'));

        $entryPoint = $root . '/public/index.php';
        $logFile = $root . '/error.log';
        self::assertTrue(copy(dirname(__DIR__, 2) . '/public/index.php', $entryPoint));
        file_put_contents($root . '/bootstrap/app.php', $this->bootstrapFixture($logFile));
        file_put_contents($root . '/routes/web.php', "<?php\nfunction broken( {\n");

        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entryPoint);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);

            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $log = (string) file_get_contents($logFile);

            self::assertStringContainsString('Handler completo', $output);
            self::assertStringNotContainsString('Erro temporário. Tente novamente.', $output);
            self::assertStringContainsString('complete error handler', $log);
            self::assertStringNotContainsString('ClipForge bootstrap failure', $log);
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }

            unlink($root . '/routes/web.php');
            unlink($root . '/bootstrap/app.php');
            unlink($entryPoint);
            rmdir($root . '/routes');
            rmdir($root . '/bootstrap');
            rmdir($root . '/public');
            rmdir($root);
        }
    }

    private function bootstrapFixture(string $logFile): string
    {
        return '<?php
declare(strict_types=1);

namespace App\Core {
    final class Session {
        public static function start(): void {}
    }

    final class Router {}
}

namespace {
    ini_set(\'error_log\', ' . var_export($logFile, true) . ');
    register_shutdown_function(static function (): void {
        error_log(\'complete error handler\');
        echo \'<main>Handler completo</main>\';
    });
}
';
    }
}
