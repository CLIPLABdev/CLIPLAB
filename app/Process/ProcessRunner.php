<?php

declare(strict_types=1);

namespace App\Process;

use InvalidArgumentException;

class ProcessRunner
{
    private const TERMINATION_GRACE_NANOSECONDS = 250000000;
    private const WINDOWS_NATIVE_EXECUTABLE_EXTENSIONS = ['.com', '.exe'];

    /** @var list<string> */
    private array $allowedBinaries;
    private string $temporaryDirectory;
    /** @var null|\Closure(string): void */
    private ?\Closure $cleanupReporter;

    /**
     * The caller must inject a writable directory outside public/public_html with private permissions.
     *
     * @param list<string> $allowedBinaries
     */
    public function __construct(array $allowedBinaries, string $temporaryDirectory, ?callable $cleanupReporter = null)
    {
        if ($allowedBinaries === []) {
            throw new InvalidArgumentException('At least one allowed binary is required.');
        }
        foreach ($allowedBinaries as $binary) {
            if (!is_string($binary) || trim($binary) === '') {
                throw new InvalidArgumentException('Allowed binaries must be non-empty strings.');
            }
        }
        $directory = $temporaryDirectory;
        $resolvedDirectory = is_string($directory) ? realpath($directory) : false;
        if ($resolvedDirectory === false || !is_dir($resolvedDirectory) || !is_writable($resolvedDirectory) || $this->isInsideProjectPublicRoot($resolvedDirectory)) {
            throw new InvalidArgumentException('The process temporary directory must be writable.');
        }
        $this->allowedBinaries = array_values(array_unique($allowedBinaries));
        $this->temporaryDirectory = $resolvedDirectory;
        $this->cleanupReporter = $cleanupReporter === null ? null : \Closure::fromCallable($cleanupReporter);
    }

    public function isExecutableAvailable(string $binary): bool
    {
        return function_exists('proc_open') && in_array($binary, $this->allowedBinaries, true)
            && $this->resolveExecutable($binary) !== null;
    }

    /** @param list<string> $command */
    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        if ($command === [] || $timeoutSeconds < 1 || $outputLimitBytes < 1) {
            throw new ProcessExecutionException('process_unavailable');
        }
        foreach ($command as $argument) {
            if (!is_string($argument)) {
                throw new ProcessExecutionException('process_unavailable');
            }
        }
        if (!in_array($command[0], $this->allowedBinaries, true) || !function_exists('proc_open')) {
            throw new ProcessExecutionException('process_unavailable');
        }
        $resolvedBinary = $this->resolveExecutable($command[0]);
        if ($resolvedBinary === null) {
            throw new ProcessExecutionException('process_unavailable');
        }
        $command[0] = $resolvedBinary;

        $windows = DIRECTORY_SEPARATOR === '\\';
        $stdoutFile = $windows ? tempnam($this->temporaryDirectory, 'cliplab-process-') : false;
        $stderrFile = $windows ? tempnam($this->temporaryDirectory, 'cliplab-process-') : false;
        if ($windows && (!is_string($stdoutFile) || !is_string($stderrFile))) {
            $this->cleanupTemporaryFiles([$stdoutFile, $stderrFile]);
            throw new ProcessExecutionException('process_unavailable');
        }

        $setsidBinary = !$windows ? $this->setsidBinary() : null;
        $processGroup = $setsidBinary !== null;
        $launchCommand = $processGroup ? array_merge([$setsidBinary, '--'], $command) : $command;
        $pipes = [];
        $process = @proc_open(
            $launchCommand,
            [
                0 => ['pipe', 'r'],
                1 => $windows ? ['file', $stdoutFile, 'wb'] : ['pipe', 'w'],
                2 => $windows ? ['file', $stderrFile, 'wb'] : ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            $this->cleanupTemporaryFiles([$stdoutFile, $stderrFile]);
            throw new ProcessExecutionException('process_unavailable');
        }

        $processId = $this->processId($process);
        $deadline = hrtime(true) + ($timeoutSeconds * 1000000000);
        $exitCode = null;
        try {
            $this->closeInputPipe($pipes);
            if ($windows) {
                $this->waitForWindowsProcess($process, (string) $stdoutFile, (string) $stderrFile, $deadline, $outputLimitBytes);
                $closeExitCode = proc_close($process);
                $process = null;
                $stdout = file_get_contents((string) $stdoutFile);
                $stderr = file_get_contents((string) $stderrFile);
                if (!is_string($stdout) || !is_string($stderr) || strlen($stdout) + strlen($stderr) > $outputLimitBytes) {
                    throw new ProcessExecutionException('process_output_limit');
                }
                return new ProcessResult($closeExitCode >= 0 ? $closeExitCode : 1, $stdout, $stderr);
            }

            foreach ([1, 2] as $descriptor) {
                if (!isset($pipes[$descriptor]) || !is_resource($pipes[$descriptor]) || !stream_set_blocking($pipes[$descriptor], false)) {
                    throw new ProcessExecutionException('process_unavailable');
                }
            }
            $stdout = '';
            $stderr = '';
            $combinedBytes = 0;
            while (true) {
                if (hrtime(true) >= $deadline) {
                    throw new ProcessExecutionException('process_timeout');
                }
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new ProcessExecutionException('process_failed');
                }
                if (!$status['running'] && is_int($status['exitcode']) && $status['exitcode'] >= 0) {
                    $exitCode = $status['exitcode'];
                }
                $read = [];
                foreach ([1, 2] as $descriptor) {
                    if (isset($pipes[$descriptor]) && is_resource($pipes[$descriptor]) && !feof($pipes[$descriptor])) {
                        $read[] = $pipes[$descriptor];
                    }
                }
                if ($read === []) {
                    if (!$status['running']) {
                        break;
                    }
                    usleep(1000);
                    continue;
                }
                $remainingNanos = max(0, $deadline - hrtime(true));
                $seconds = intdiv($remainingNanos, 1000000000);
                $microseconds = min(100000, (int) (($remainingNanos % 1000000000) / 1000));
                $write = null;
                $except = null;
                $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
                if ($selected === false) {
                    throw new ProcessExecutionException('process_failed');
                }
                if ($selected === 0) {
                    continue;
                }
                foreach ($read as $stream) {
                    $remaining = $outputLimitBytes - $combinedBytes;
                    $chunk = stream_get_contents($stream, $remaining + 1);
                    if ($chunk === false) {
                        throw new ProcessExecutionException('process_failed');
                    }
                    $combinedBytes += strlen($chunk);
                    if ($combinedBytes > $outputLimitBytes) {
                        throw new ProcessExecutionException('process_output_limit');
                    }
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
            $closeExitCode = proc_close($process);
            $process = null;
            $resolvedExitCode = $exitCode ?? $closeExitCode;
            return new ProcessResult($resolvedExitCode >= 0 ? $resolvedExitCode : 1, $stdout, $stderr);
        } finally {
            $this->closePipes($pipes);
            if (is_resource($process)) {
                $this->terminateAndClose($process, $processId, $processGroup);
            }
            $this->cleanupTemporaryFiles([$stdoutFile, $stderrFile]);
        }
    }

    /** @param array<int, resource> $pipes */
    private function closeInputPipe(array &$pipes): void
    {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** @param resource $process */
    private function waitForWindowsProcess($process, string $stdoutFile, string $stderrFile, int $deadline, int $outputLimitBytes): void
    {
        $lastBytes = -1;
        $exitedAt = null;
        while (true) {
            if (hrtime(true) >= $deadline) {
                throw new ProcessExecutionException('process_timeout');
            }
            $status = proc_get_status($process);
            if (!is_array($status)) {
                throw new ProcessExecutionException('process_failed');
            }
            clearstatcache(true, $stdoutFile);
            clearstatcache(true, $stderrFile);
            $bytes = (filesize($stdoutFile) ?: 0) + (filesize($stderrFile) ?: 0);
            if ($bytes > $outputLimitBytes) {
                throw new ProcessExecutionException('process_output_limit');
            }
            if ($status['running']) {
                $lastBytes = $bytes;
                usleep(10000);
                continue;
            }
            if ($exitedAt === null) {
                $exitedAt = hrtime(true);
                $lastBytes = $bytes;
                usleep(50000);
                continue;
            }
            if ($bytes !== $lastBytes) {
                $lastBytes = $bytes;
                usleep(10000);
                continue;
            }
            return;
        }
    }

    /** @param resource $process */
    private function terminateAndClose($process, ?int $processId, bool $processGroup): void
    {
        if (DIRECTORY_SEPARATOR === '\\' && $processId !== null) {
            $this->forceTerminateWindowsProcessTree($processId);
        }

        if ($processGroup && $processId !== null && function_exists('posix_kill')) {
            if (defined('SIGTERM')) {
                @posix_kill(-$processId, SIGTERM);
            }
            // A parent can exit while its descendants keep an inherited output pipe open.
            usleep((int) (self::TERMINATION_GRACE_NANOSECONDS / 1000));
            if (defined('SIGKILL')) {
                @posix_kill(-$processId, SIGKILL);
            }
        }

        $status = proc_get_status($process);
        if (is_array($status) && $status['running']) {
            @proc_terminate($process);
            if (!$this->waitForProcessToStop($process, self::TERMINATION_GRACE_NANOSECONDS)) {
                @proc_terminate($process, 9);
                $this->waitForProcessToStop($process, self::TERMINATION_GRACE_NANOSECONDS);
            }
        }
        $status = proc_get_status($process);
        if (is_array($status) && !$status['running']) {
            @proc_close($process);
        }
    }
    /** @param resource $process */
    private function waitForProcessToStop($process, int $waitNanos): bool
    {
        $deadline = hrtime(true) + $waitNanos;
        do {
            $status = proc_get_status($process);
            if (!is_array($status) || !$status['running']) {
                return true;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);

        $status = proc_get_status($process);
        return !is_array($status) || !$status['running'];
    }

    private function forceTerminateWindowsProcessTree(int $processId): void
    {
        $taskkill = $this->windowsTaskkillBinary();
        if ($taskkill === null || $processId < 1) {
            return;
        }
        $pipes = [];
        $terminator = @proc_open(
            [$taskkill, '/PID', (string) $processId, '/T', '/F'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', 'NUL', 'a'],
                2 => ['file', 'NUL', 'a'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($terminator)) {
            return;
        }

        $this->closePipes($pipes);
        if (!$this->waitForProcessToStop($terminator, self::TERMINATION_GRACE_NANOSECONDS)) {
            @proc_terminate($terminator, 9);
            $this->waitForProcessToStop($terminator, self::TERMINATION_GRACE_NANOSECONDS);
        }
        $status = proc_get_status($terminator);
        if (is_array($status) && !$status['running']) {
            @proc_close($terminator);
        }
    }
    /** @param resource $process */
    private function processId($process): ?int
    {
        $status = proc_get_status($process);
        return is_array($status) && isset($status['pid']) && is_int($status['pid']) && $status['pid'] > 0 ? $status['pid'] : null;
    }

    private function resolveExecutable(string $binary): ?string
    {
        if ($binary === '' || trim($binary) !== $binary || strpos($binary, "\0") !== false) {
            return null;
        }
        if ($this->isAbsolutePath($binary)) {
            return $this->checkedExecutable($binary);
        }
        if (strpos($binary, '/') !== false || strpos($binary, '\\') !== false
            || (DIRECTORY_SEPARATOR === '\\' && strpos($binary, ':') !== false)) {
            return null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }
        $names = $this->executableNames($binary);
        if ($names === []) {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $entry) {
            $directory = $this->resolvedPathDirectory($entry);
            if ($directory === null) {
                continue;
            }
            foreach ($names as $name) {
                $resolved = $this->checkedExecutable($directory . DIRECTORY_SEPARATOR . $name);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function checkedExecutable(string $candidate): ?string
    {
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }
        if (DIRECTORY_SEPARATOR === '\\'
            && !in_array('.' . strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), self::WINDOWS_NATIVE_EXECUTABLE_EXTENSIONS, true)) {
            return null;
        }
        if (!is_executable($resolved)) {
            return null;
        }

        return $resolved;
    }

    private function resolvedPathDirectory(string $entry): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $entry = trim($entry);
            if (strlen($entry) >= 2 && $entry[0] === '"' && $entry[strlen($entry) - 1] === '"') {
                $entry = substr($entry, 1, -1);
            }
            if (strpos($entry, '"') !== false) {
                return null;
            }
        }
        if ($entry === '' || !$this->isAbsolutePath($entry)) {
            return null;
        }
        $resolved = realpath($entry);

        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    /** @return list<string> */
    private function executableNames(string $binary): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [$binary];
        }
        $extensions = $this->windowsExecutableExtensions();
        $extension = pathinfo($binary, PATHINFO_EXTENSION);
        if ($extension !== '') {
            return in_array('.' . strtolower($extension), $extensions, true) ? [$binary] : [];
        }

        return array_map(static fn (string $suffix): string => $binary . $suffix, $extensions);
    }

    /** @return list<string> */
    private function windowsExecutableExtensions(): array
    {
        $configured = getenv('PATHEXT');
        $parts = is_string($configured) ? explode(';', $configured) : [];
        $extensions = [];
        foreach ($parts as $part) {
            $extension = strtolower(trim($part));
            if (!in_array($extension, self::WINDOWS_NATIVE_EXECUTABLE_EXTENSIONS, true) || in_array($extension, $extensions, true)) {
                continue;
            }
            $extensions[] = $extension;
        }

        return $extensions === [] ? self::WINDOWS_NATIVE_EXECUTABLE_EXTENSIONS : $extensions;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            return $path[0] === '/';
        }
        $drive = ord($path[0]);
        $driveIsAsciiLetter = ($drive >= 65 && $drive <= 90) || ($drive >= 97 && $drive <= 122);
        if (strlen($path) >= 3 && $driveIsAsciiLetter && $path[1] === ':'
            && ($path[2] === '\\' || $path[2] === '/')) {
            return true;
        }

        return strlen($path) >= 2
            && (($path[0] === '\\' && $path[1] === '\\') || ($path[0] === '/' && $path[1] === '/'));
    }

    private function setsidBinary(): ?string
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function windowsTaskkillBinary(): ?string
    {
        $systemRoot = getenv('SystemRoot');
        if (!is_string($systemRoot) || trim($systemRoot) === '') {
            return null;
        }
        $candidate = rtrim($systemRoot, "\\/") . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'taskkill.exe';
        $resolved = realpath($candidate);

        return $resolved !== false && is_file($resolved) ? $resolved : null;
    }

    private function isInsideProjectPublicRoot(string $directory): bool
    {
        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot === false) {
            return false;
        }
        foreach (['public', 'public_html'] as $name) {
            $publicRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . $name);
            if ($publicRoot === false) {
                continue;
            }
            $prefix = rtrim($publicRoot, "\\/") . DIRECTORY_SEPARATOR;
            $candidate = DIRECTORY_SEPARATOR === '\\' ? strtolower($directory) : $directory;
            $expected = DIRECTORY_SEPARATOR === '\\' ? strtolower($prefix) : $prefix;
            if ($candidate === rtrim($expected, DIRECTORY_SEPARATOR) || strncmp($candidate, $expected, strlen($expected)) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string|false> $files */
    private function cleanupTemporaryFiles(array $files): void
    {
        foreach ($files as $file) {
            if (!is_string($file) || $file === '' || !file_exists($file)) {
                continue;
            }
            if (!@unlink($file) && $this->cleanupReporter !== null) {
                ($this->cleanupReporter)('process_cleanup_failed');
            }
        }
    }
}
