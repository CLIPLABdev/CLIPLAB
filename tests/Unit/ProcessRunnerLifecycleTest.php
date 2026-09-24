<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerLifecycleTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliplab-runner-lifecycle-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700);
        @chmod($this->temporaryDirectory, 0700);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            @rmdir($this->temporaryDirectory);
        }
    }
    public function testRequiresAnExplicitTemporaryDirectory(): void
    {
        self::assertSame(2, (new \ReflectionMethod(ProcessRunner::class, '__construct'))->getNumberOfRequiredParameters());
    }

    public function testRejectsATemporaryDirectoryInsideTheProjectPublicRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProcessRunner([PHP_BINARY], dirname(__DIR__, 2) . '/public');
    }

    public function testRejectsAnUnavailableConfiguredPrivateTemporaryDirectory(): void
    {
        $missingDirectory = sys_get_temp_dir() . '/cliplab-missing-' . bin2hex(random_bytes(6));

        $this->expectException(InvalidArgumentException::class);
        new ProcessRunner([PHP_BINARY], $missingDirectory);
    }

    public function testDetectsAChildThatKeepsWritingAfterItsDirectParentExits(): void
    {
        $runner = new ProcessRunner([PHP_BINARY], $this->temporaryDirectory);
        $child = DIRECTORY_SEPARATOR === '\\'
            ? 'while (true) { echo str_repeat("x", 4096); }'
            : '$process = proc_open([PHP_BINARY, "-r", "while (true) { echo str_repeat(\"x\", 4096); }"] , [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes); exit(0);';

        try {
            $runner->run([PHP_BINARY, '-r', $child], 2, 4096);
            self::fail('A descendant that kept the output stream active returned normally.');
        } catch (ProcessExecutionException $exception) {
            self::assertContains($exception->publicCode(), ['process_timeout', 'process_output_limit']);
        }
    }

    public function testTimeoutCleansUpABusyProcessWithinABoundedInterval(): void
    {
        $runner = new ProcessRunner([PHP_BINARY], $this->temporaryDirectory);
        $startedAt = microtime(true);

        try {
            $runner->run([PHP_BINARY, '-r', 'if (function_exists("pcntl_async_signals")) { pcntl_async_signals(true); pcntl_signal(SIGTERM, static function (): void { }); } while (true) { }'], 1, 4096);
            self::fail('The busy child completed instead of timing out.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_timeout', $exception->publicCode());
        }

        self::assertLessThan(3.0, microtime(true) - $startedAt);
    }
}
