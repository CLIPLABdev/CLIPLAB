<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;

final class ClipRenderException extends RuntimeException
{
    /** @var list<string> */
    private const CODES = [
        'render_unavailable',
        'render_timeout',
        'render_failed',
        'render_output_invalid',
    ];

    private function __construct(private string $publicCode)
    {
        if (!in_array($publicCode, self::CODES, true)) {
            throw new InvalidArgumentException('Unsupported clip render failure code.');
        }
        parent::__construct('The clip could not be rendered.');
    }

    public static function withCode(string $publicCode): self
    {
        return new self($publicCode);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }
}
