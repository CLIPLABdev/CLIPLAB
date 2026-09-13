<?php

declare(strict_types=1);

namespace App\Queue;

interface TransientJobFailure
{
    public function publicCode(): string;
}
