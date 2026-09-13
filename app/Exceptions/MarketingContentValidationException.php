<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class MarketingContentValidationException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        private ?int $record = null,
        private ?string $field = null
    ) {
        parent::__construct($message);
    }

    public function record(): ?int
    {
        return $this->record;
    }

    public function field(): ?string
    {
        return $this->field;
    }
}
