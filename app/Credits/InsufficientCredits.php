<?php

declare(strict_types=1);

namespace App\Credits;

use InvalidArgumentException;
use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    private int $available;
    private int $required;

    public function __construct(int $available, int $required)
    {
        if ($available < 0 || $required < 1 || $required > 2147483647) {
            throw new InvalidArgumentException('Credit amounts are invalid.');
        }

        parent::__construct('Créditos insuficientes.');
        $this->available = $available;
        $this->required = $required;
    }

    public function available(): int
    {
        return $this->available;
    }

    public function required(): int
    {
        return $this->required;
    }
}
