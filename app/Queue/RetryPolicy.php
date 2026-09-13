<?php

declare(strict_types=1);

namespace App\Queue;

final class RetryPolicy
{
    /** @var \Closure(int,int):int */
    private \Closure $random;

    public function __construct(?callable $random = null)
    {
        $this->random = $random === null ? \Closure::fromCallable('random_int') : \Closure::fromCallable($random);
    }

    public function delaySeconds(ClaimedJob $job, ?int $retryAfterSeconds = null): int
    {
        $base = min(900, 15 * (2 ** min(6, max(0, $job->attempts() - 1))));
        if ($job->type() !== 'analyze_video') {
            return $base;
        }

        $jitterLimit = (int) floor($base / 4);
        $jitter = max(0, min($jitterLimit, ($this->random)(0, $jitterLimit)));

        return min(900, max($base + $jitter, $retryAfterSeconds ?? 0));
    }
}
