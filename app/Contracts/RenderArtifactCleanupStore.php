<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Queue\ClaimedJob;

interface RenderArtifactCleanupStore
{
    /** @param list<string> $objectKeys */
    public function reserve(ClaimedJob $job, array $objectKeys): bool;

    /** @param list<string> $objectKeys */
    public function markForCleanup(ClaimedJob $job, array $objectKeys): void;

    /** @param list<string> $objectKeys */
    public function release(ClaimedJob $job, array $objectKeys): void;

    /** @return list<string> */
    public function pending(int $limit = 25): array;

    public function forget(string $objectKey): void;
}
