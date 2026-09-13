<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class LocalRuntimeLauncherTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('The local launcher targets Windows PowerShell.');
        }
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-local-launcher-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'public', 0700, true);
        mkdir($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'bin', 0700, true);
        file_put_contents(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
            '<?php http_response_code(200); echo "ready";'
        );
        file_put_contents(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'process-jobs.php',
            <<<'PHP'
<?php
$root = dirname(__DIR__);
$lock = $root . '/worker.lock';
if (file_exists($lock)) {
    echo json_encode(['claimed' => 0, 'completed' => 0, 'retried' => 0, 'deferred' => 0, 'failed' => 0, 'operational_errors' => 1]);
    exit(9);
}
file_put_contents($lock, (string) getmypid());
usleep(120000);
if (file_exists($root . '/hold-worker-output')) {
    $command = 'start /B "" ' . escapeshellarg(PHP_BINARY) . ' -r "sleep(4);"';
    pclose(popen($command, 'r'));
}
file_put_contents($root . '/runs.jsonl', json_encode([
    'upload' => ini_get('upload_max_filesize'),
    'post' => ini_get('post_max_size'),
    'args' => array_slice($argv, 1),
]) . PHP_EOL, FILE_APPEND);
unlink($lock);
echo json_encode([
    'claimed' => 1,
    'completed' => 1,
    'retried' => 0,
    'deferred' => 0,
    'failed' => 0,
    'operational_errors' => 0,
    'payload' => 'PAYLOAD_CANARY',
]);
PHP
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->temporaryDirectory) || !is_dir($this->temporaryDirectory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->temporaryDirectory);
    }

    public function testRunsFiniteWorkersSeriallyWithScopedIniAndStopsItsWebChild(): void
    {
        $port = $this->availablePort();
        $result = $this->runPowerShell([
            '-PhpBin', PHP_BINARY,
            '-ProjectRoot', $this->temporaryDirectory,
            '-Port', (string) $port,
            '-WithWorker',
            '-WorkerIterations', '2',
            '-PollSeconds', '1',
        ], 20);

        self::assertSame(0, $result['exit_code'], $result['stderr'] . PHP_EOL . $result['stdout']);
        $events = array_values(array_filter(array_map(
            static fn (string $line): ?array => trim($line) === '' ? null : json_decode($line, true, 32, JSON_THROW_ON_ERROR),
            preg_split('/\R/', $result['stdout']) ?: []
        )));
        self::assertSame(['web_ready', 'worker_run', 'worker_run', 'runtime_stopped'], array_column($events, 'event'));
        self::assertSame([0, 0], array_column(array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['event'] === 'worker_run'
        )), 'exit_code'));
        self::assertStringNotContainsString('PAYLOAD_CANARY', $result['stdout'] . $result['stderr']);

        $runs = array_values(array_filter(array_map(
            static fn (string $line): ?array => trim($line) === '' ? null : json_decode($line, true, 32, JSON_THROW_ON_ERROR),
            file($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'runs.jsonl', FILE_IGNORE_NEW_LINES) ?: []
        )));
        self::assertCount(2, $runs);
        foreach ($runs as $run) {
            self::assertSame('500M', $run['upload']);
            self::assertSame('501M', $run['post']);
            self::assertSame(['--queue=media', '--limit=1', '--time-budget=50'], $run['args']);
        }
        self::assertFalse($this->portAcceptsConnections($port), 'The launcher left its PHP web child running.');
    }

    public function testWindowsPowerShellFiveStartsFromTheScriptDefaultProjectRoot(): void
    {
        $tools = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'tools';
        mkdir($tools, 0700, true);
        $script = $tools . DIRECTORY_SEPARATOR . 'start-local.ps1';
        copy(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'start-local.ps1', $script);
        $port = $this->availablePort();

        $result = $this->runPowerShell([
            '-PhpBin', PHP_BINARY,
            '-Port', (string) $port,
            '-WithWorker',
            '-WorkerIterations', '1',
        ], 15, $script);

        self::assertSame(0, $result['exit_code'], $result['stderr'] . PHP_EOL . $result['stdout']);
        self::assertStringContainsString('"event":"web_ready"', $result['stdout']);
        self::assertStringContainsString('"event":"worker_run"', $result['stdout']);
        self::assertFalse($this->portAcceptsConnections($port), 'The default-root launch left its PHP web child running.');
    }

    public function testWorkerDescendantHoldingRedirectedOutputDoesNotStopRuntimeOrLeakWorkerLogs(): void
    {
        file_put_contents($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'hold-worker-output', '1');
        $port = $this->availablePort();
        $workerDirectoriesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-local-worker-*') ?: [];

        $result = $this->runPowerShell([
            '-PhpBin', PHP_BINARY,
            '-ProjectRoot', $this->temporaryDirectory,
            '-Port', (string) $port,
            '-WithWorker',
            '-WorkerIterations', '1',
        ], 15);

        self::assertSame(0, $result['exit_code'], $result['stderr'] . PHP_EOL . $result['stdout']);
        self::assertSame('', trim($result['stderr']));
        self::assertStringContainsString('"event":"worker_run"', $result['stdout']);
        self::assertStringContainsString('"event":"runtime_stopped"', $result['stdout']);
        self::assertSame(
            $workerDirectoriesBefore,
            glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-local-worker-*') ?: [],
            'The launcher left redirected worker output in the temporary directory.'
        );
    }

    public function testUnknownFailureReportsOnlySanitizedDiagnosticFields(): void
    {
        $secretCanary = 'SECRET_PATH_CANARY';
        $result = $this->runPowerShell([
            '-PhpBin', $this->temporaryDirectory . DIRECTORY_SEPARATOR . $secretCanary . '.exe',
            '-ProjectRoot', $this->temporaryDirectory,
            '-Port', (string) $this->availablePort(),
        ], 10);

        self::assertNotSame(0, $result['exit_code']);
        $error = json_decode(trim($result['stderr']), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['event', 'error', 'exception_type', 'line'], array_keys($error));
        self::assertSame('runtime_error', $error['event']);
        self::assertSame('runtime_start_failed', $error['error']);
        self::assertSame('System.Management.Automation.ItemNotFoundException', $error['exception_type']);
        self::assertIsInt($error['line']);
        self::assertGreaterThan(0, $error['line']);
        self::assertStringNotContainsString($secretCanary, $result['stdout'] . $result['stderr']);
    }

    public function testRefusesAnOccupiedPortWithoutStoppingItsOwner(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        $port = (int) substr(strrchr($name, ':'), 1);

        try {
            $result = $this->runPowerShell([
                '-PhpBin', PHP_BINARY,
                '-ProjectRoot', $this->temporaryDirectory,
                '-Port', (string) $port,
            ], 10);
            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('port_in_use', $result['stderr'] . $result['stdout']);
            self::assertIsResource($socket, 'The launcher closed a listener it did not create.');
        } finally {
            fclose($socket);
        }
    }

    /** @param list<string> $arguments @return array{exit_code:int,stdout:string,stderr:string} */
    private function runPowerShell(array $arguments, int $timeoutSeconds, ?string $script = null): array
    {
        $powerShell = getenv('TEST_POWERSHELL_BIN');
        if (!is_string($powerShell) || $powerShell === '') {
            $powerShell = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        }
        if (!is_file($powerShell)) {
            self::markTestSkipped('Windows PowerShell is unavailable.');
        }
        $script ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'start-local.ps1';
        $command = array_merge([$powerShell, '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process);
            self::fail('The local launcher exceeded its bounded test deadline.');
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode === -1 && isset($status['exitcode']) && $status['exitcode'] >= 0) {
            $exitCode = $status['exitcode'];
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function portAcceptsConnections(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 0.25);
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
