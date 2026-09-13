<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerDescendantTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-runner-descendant-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700);
        @chmod($this->temporaryDirectory, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testUsesTheSupportedPlatformSpecificCleanupContract(): void
    {
        $marker = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'child.pid';
        $childPid = null;
        $startedAt = microtime(true);
        try {
            $runner = new ProcessRunner([PHP_BINARY], $this->temporaryDirectory);
            if (DIRECTORY_SEPARATOR === '\\') {
                // PHP has no Job Object API. Production only permits direct ffprobe here, so
                // the supported Windows contract is a bounded direct process and file cleanup.
                $result = $runner->run([PHP_BINARY, '-r', 'echo "ready";'], 1, 4096);

                self::assertSame(0, $result->exitCode);
                self::assertSame('ready', $result->stdout);
                self::assertSame('', $result->stderr);
                self::assertLessThan(3.0, microtime(true) - $startedAt, 'The direct process did not return within its bounded deadline.');
                self::assertSame([], $this->processTemporaryFiles(), 'The direct process left temporary output handles behind.');

                return;
            }

            $childProgram = 'file_put_contents(' . var_export($marker, true) . ', (string) getmypid()); while (true) { echo "x"; usleep(10000); }';
            $parentProgram = '$child = ' . var_export($childProgram, true) . '; '
                . '$process = proc_open([PHP_BINARY, "-r", $child], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes); '
                . 'if (!is_resource($process)) { exit(2); } '
                . '$deadline = microtime(true) + 1.0; while (!file_exists(' . var_export($marker, true) . ') && microtime(true) < $deadline) { usleep(1000); } '
                . 'usleep(200000); exit(0);';

            try {
                $runner->run([PHP_BINARY, '-r', $parentProgram], 1, 4096);
                self::fail('A parent-exited child process returned normally.');
            } catch (ProcessExecutionException $exception) {
                self::assertSame('process_timeout', $exception->publicCode());
            }

            self::assertFileExists($marker);
            $childPid = (int) trim((string) file_get_contents($marker));
            self::assertGreaterThan(0, $childPid);
            self::assertLessThan(3.0, microtime(true) - $startedAt, 'The runner did not return within its bounded timeout and cleanup window.');
            usleep(100000);
            self::assertFalse($this->processIsRunning($childPid), 'The runner left the POSIX process-group descendant alive.');
        } finally {
            if (!is_int($childPid) || $childPid < 1) {
                $childPid = $this->pidFromMarker($marker);
            }
            if (is_int($childPid) && $childPid > 0) {
                $this->terminate($childPid);
            }
            @unlink($marker);
            $this->removeTemporaryDirectory();
        }
    }

    /** @return list<string> */
    private function processTemporaryFiles(): array
    {
        $files = glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'clipforge-process-*');

        return $files === false ? [] : array_values($files);
    }

    private function pidFromMarker(string $marker): ?int
    {
        if (!is_file($marker)) {
            return null;
        }
        $pid = (int) trim((string) file_get_contents($marker));

        return $pid > 0 ? $pid : null;
    }

    private function removeTemporaryDirectory(): void
    {
        if (!isset($this->temporaryDirectory) || !is_dir($this->temporaryDirectory)) {
            return;
        }
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->temporaryDirectory);
    }

    private function processIsRunning(int $pid): bool
    {
        $statusFile = '/proc/' . $pid . '/stat';
        if (is_file($statusFile)) {
            $status = file_get_contents($statusFile);

            return is_string($status) && !str_contains($status, ') Z ');
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    private function terminate(int $pid): void
    {
        if (function_exists('posix_kill') && defined('SIGKILL')) {
            @posix_kill($pid, SIGKILL);
        }
    }
}
