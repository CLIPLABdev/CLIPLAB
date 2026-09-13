<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class ProjectStatusPollingConcurrencyTest extends TestCase
{
    public function testVisibilityResumeDoesNotStartASecondPollWhileFetchIsPending(): void
    {
        if (!$this->nodeIsAvailable()) {
            self::markTestSkipped('Node.js is unavailable for the browser behavior regression.');
        }

        $root = dirname(__DIR__, 2);
        $process = @proc_open(
            ['node', $root . '/tests/Browser/project-status-concurrency.test.js', $root . '/public/assets/js/project-status.js'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, (string) $stderr);
        self::assertSame("ok\n", $stdout);
    }

    private function nodeIsAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $process = @proc_open(
            ['node', '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return false;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_get_contents($pipe);
                fclose($pipe);
            }
        }

        return proc_close($process) === 0;
    }
}
