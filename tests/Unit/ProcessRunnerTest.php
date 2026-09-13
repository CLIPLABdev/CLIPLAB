<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    private string $phpBinary;
    private ProcessRunner $runner;
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->phpBinary = PHP_BINARY;
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-runner-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700);
        @chmod($this->temporaryDirectory, 0700);
        $this->runner = new ProcessRunner([$this->phpBinary], $this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            @rmdir($this->temporaryDirectory);
        }
    }

    public function testPassesArgumentsWithoutShellInterpolation(): void
    {
        $result = $this->runner->run([$this->phpBinary, '-r', 'echo $argv[1];', 'name;echo injected'], 5, 4096);

        self::assertSame(0, $result->exitCode);
        self::assertSame('name;echo injected', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testRejectsACommandWhoseBinaryIsNotAllowlisted(): void
    {
        try {
            $this->runner->run(['not-allowlisted', '-version'], 5, 4096);
            self::fail('A non-allowlisted binary was accepted.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_unavailable', $exception->publicCode());
            self::assertStringNotContainsString('not-allowlisted', $exception->getMessage());
        }
    }

    public function testMissingAllowlistedBareExecutableIsReportedAsUnavailable(): void
    {
        $missingBinary = 'clipforge-missing-' . bin2hex(random_bytes(12));
        $runner = new ProcessRunner([$missingBinary], $this->temporaryDirectory);

        try {
            $runner->run([$missingBinary, '--version'], 5, 4096);
            self::fail('A missing allowlisted executable was launched.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_unavailable', $exception->publicCode());
            self::assertStringNotContainsString($missingBinary, $exception->getMessage());
        }
    }

    public function testAllowlistedAbsoluteDirectoryIsReportedAsUnavailable(): void
    {
        $runner = new ProcessRunner([$this->temporaryDirectory], $this->temporaryDirectory);

        try {
            $runner->run([$this->temporaryDirectory], 5, 4096);
            self::fail('A directory was accepted as an executable.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_unavailable', $exception->publicCode());
            self::assertStringNotContainsString($this->temporaryDirectory, $exception->getMessage());
        }
    }

    public function testAllowlistedNonExecutableFileIsReportedAsUnavailable(): void
    {
        $file = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'not-executable-' . bin2hex(random_bytes(8)) . '.txt';
        self::assertNotFalse(file_put_contents($file, 'not a program'));
        @chmod($file, 0600);
        $runner = new ProcessRunner([$file], $this->temporaryDirectory);

        try {
            $runner->run([$file], 5, 4096);
            self::fail('A non-executable file was accepted.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_unavailable', $exception->publicCode());
            self::assertStringNotContainsString($file, $exception->getMessage());
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testRejectsAllowlistedAbsoluteWindowsCommandScripts(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Windows command-script behavior is Windows-specific.');
        }

        foreach (['bat', 'cmd'] as $extension) {
            $script = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'clipforge-script-' . bin2hex(random_bytes(8)) . '.' . $extension;
            self::assertTrue(copy($this->phpBinary, $script));
            $runner = new ProcessRunner([$script], $this->temporaryDirectory);

            try {
                $runner->run([$script], 5, 4096);
                self::fail('A Windows command script was accepted as a native executable.');
            } catch (ProcessExecutionException $exception) {
                self::assertSame('process_unavailable', $exception->publicCode());
                self::assertStringNotContainsString($script, $exception->getMessage());
            } finally {
                if (is_file($script)) {
                    @unlink($script);
                }
            }
        }
    }

    public function testSkipsWindowsCommandScriptsWhenResolvingBareExecutable(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Windows PATHEXT behavior is Windows-specific.');
        }

        $binary = 'clipforge-native-' . bin2hex(random_bytes(8));
        $scriptDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'script-bin';
        $nativeDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'native-bin';
        mkdir($scriptDirectory, 0700);
        mkdir($nativeDirectory, 0700);
        $script = $scriptDirectory . DIRECTORY_SEPARATOR . $binary . '.cmd';
        $native = $nativeDirectory . DIRECTORY_SEPARATOR . $binary . '.exe';
        $previousPath = getenv('PATH');
        $previousPathExt = getenv('PATHEXT');
        self::assertTrue(copy($this->phpBinary, $script));
        self::assertTrue(copy($this->phpBinary, $native));
        putenv('PATH=' . $scriptDirectory . PATH_SEPARATOR . $nativeDirectory . PATH_SEPARATOR . dirname($this->phpBinary));
        putenv('PATHEXT=.CMD;.EXE');
        $runner = new ProcessRunner([$binary], $this->temporaryDirectory);

        try {
            $result = $runner->run([$binary, '-r', 'echo "safe";'], 5, 4096);
            self::assertSame(0, $result->exitCode);
            self::assertSame('safe', $result->stdout);
            self::assertSame('', $result->stderr);
        } finally {
            $this->restoreEnvironmentVariable('PATH', $previousPath);
            $this->restoreEnvironmentVariable('PATHEXT', $previousPathExt);
            foreach ([$script, $native] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            foreach ([$scriptDirectory, $nativeDirectory] as $directory) {
                if (is_dir($directory)) {
                    @rmdir($directory);
                }
            }
        }
    }

    public function testIgnoresEmptyPathEntriesInsteadOfLaunchingFromTheWorkingDirectory(): void
    {
        $extension = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        $binary = 'clipforge-unsafe-path-' . bin2hex(random_bytes(12)) . $extension;
        $previousWorkingDirectory = getcwd();
        self::assertIsString($previousWorkingDirectory);
        $workingCopy = $this->temporaryDirectory . DIRECTORY_SEPARATOR . $binary;
        $previousPath = getenv('PATH');
        self::assertTrue(copy($this->phpBinary, $workingCopy));
        @chmod($workingCopy, 0700);
        self::assertTrue(chdir($this->temporaryDirectory));
        putenv('PATH=' . PATH_SEPARATOR);
        $runner = new ProcessRunner([$binary], $this->temporaryDirectory);

        try {
            $runner->run([$binary, '-r', 'echo "unsafe";'], 5, 4096);
            self::fail('An empty PATH entry resolved an executable from the working directory.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_unavailable', $exception->publicCode());
            self::assertStringNotContainsString($binary, $exception->getMessage());
        } finally {
            if (is_string($previousPath)) {
                putenv('PATH=' . $previousPath);
            } else {
                putenv('PATH');
            }
            chdir($previousWorkingDirectory);
            if (is_file($workingCopy)) {
                @unlink($workingCopy);
            }
        }
    }

    public function testResolvesBareExecutableFromAnAbsolutePathEntry(): void
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        $binary = 'clipforge-safe-path-' . bin2hex(random_bytes(12));
        $filename = $binary . ($windows ? '.EXE' : '');
        $directory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'bin';
        mkdir($directory, 0700);
        $executable = $directory . DIRECTORY_SEPARATOR . $filename;
        $previousPath = getenv('PATH');
        $previousPathExt = getenv('PATHEXT');
        self::assertTrue(copy($this->phpBinary, $executable));
        @chmod($executable, 0700);
        putenv('PATH=' . $directory . PATH_SEPARATOR . dirname($this->phpBinary));
        if ($windows) {
            putenv('PATHEXT=;.eXe;..\\unsafe;;');
        }
        $runner = new ProcessRunner([$binary], $this->temporaryDirectory);

        try {
            $result = $runner->run([$binary, '-r', 'echo "safe";'], 5, 4096);
            self::assertSame(0, $result->exitCode);
            self::assertSame('safe', $result->stdout);
            self::assertSame('', $result->stderr);
        } finally {
            $this->restoreEnvironmentVariable('PATH', $previousPath);
            $this->restoreEnvironmentVariable('PATHEXT', $previousPathExt);
            if (is_file($executable)) {
                @unlink($executable);
            }
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function testRejectsNonStringArgumentsBeforeOpeningAProcess(): void
    {
        try {
            $this->runner->run([$this->phpBinary, 123], 5, 4096);
            self::fail('A non-string argument was accepted.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_unavailable', $exception->publicCode());
        }
    }

    public function testTerminatesOnTimeoutAndClosesTheChildProcess(): void
    {
        try {
            $this->runner->run([$this->phpBinary, '-r', 'usleep(3000000);'], 1, 4096);
            self::fail('A timed out process returned normally.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_timeout', $exception->publicCode());
        }
    }

    public function testTerminatesWhenCombinedOutputExceedsTheLimit(): void
    {
        try {
            $this->runner->run([$this->phpBinary, '-r', 'while (true) { echo str_repeat("x", 4096); }'], 5, 4096);
            self::fail('An unbounded child output returned normally.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_output_limit', $exception->publicCode());
        }
    }

    /** @param string|false $value */
    private function restoreEnvironmentVariable(string $name, $value): void
    {
        if (is_string($value)) {
            putenv($name . '=' . $value);
        } else {
            putenv($name);
        }
    }
}
