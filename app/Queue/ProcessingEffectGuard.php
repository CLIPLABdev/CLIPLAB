<?php

declare(strict_types=1);

namespace App\Queue;

interface ProcessingEffectGuard
{
    /** @param callable(): void $effect */
    public function apply(ClaimedJob $job, callable $effect): bool;
}
