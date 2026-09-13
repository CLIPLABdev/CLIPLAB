<?php

declare(strict_types=1);

namespace App\Plans;

use RuntimeException;

final class PlanLimitExceeded extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        private int $limit,
        private int $used,
        private int $requested
    ) {
        parent::__construct('O limite contratado foi atingido.');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function publicCode(): string
    {
        return $this->errorCode;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function used(): int
    {
        return $this->used;
    }

    public function requested(): int
    {
        return $this->requested;
    }
}
