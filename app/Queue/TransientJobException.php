<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;
use RuntimeException;

final class TransientJobException extends RuntimeException implements TransientJobFailure
{
    /** @var list<string> */
    private const CODES = ['processor_unavailable', 'process_timeout', 'network_timeout', 'download_unavailable'];

    public function __construct(private string $publicCode)
    {
        if (!in_array($publicCode, self::CODES, true)) {
            throw new InvalidArgumentException('Transient job failure code is not supported.');
        }
        parent::__construct('Transient job failure.');
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }
}
