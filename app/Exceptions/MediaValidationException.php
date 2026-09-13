<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class MediaValidationException extends RuntimeException
{
    private string $publicCode;

    private function __construct(string $publicCode)
    {
        parent::__construct('The media source could not be accepted.');
        $this->publicCode = $publicCode;
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
