<?php

declare(strict_types=1);

namespace App\Process;

use InvalidArgumentException;

final class ProcessResult
{
    public int $exitCode;
    public string $stdout;
    public string $stderr;

    public function __construct(int $exitCode, string $stdout, string $stderr)
    {
        if ($exitCode < 0) {
            throw new InvalidArgumentException('Process exit code must not be negative.');
        }
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }
}
