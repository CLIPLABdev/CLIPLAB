<?php

declare(strict_types=1);

namespace App\Process;

use InvalidArgumentException;
use RuntimeException;

final class ProcessExecutionException extends RuntimeException
{
    /** @var list<string> */
    private const CODES = ['process_unavailable', 'process_timeout', 'process_output_limit', 'process_failed'];

    public function __construct(private string $publicCode)
    {
        if (!in_array($publicCode, self::CODES, true)) {
            throw new InvalidArgumentException('Unsupported process failure code.');
        }
        parent::__construct('The media process could not be completed.');
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }
}
