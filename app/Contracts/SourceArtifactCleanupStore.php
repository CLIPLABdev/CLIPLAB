<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Queue\ClaimedJob;

interface SourceArtifactCleanupStore
{
    /** Commit a reservation before writing bytes. Returns false when the supplied job lost its lease. */
    public function reserve(string $objectKey, ?ClaimedJob $job = null): bool;

    /** Requires the same transaction that will publish the source. Reject absent or expired reservations. */
    public function lockForPublication(string $objectKey): void;

    /** Release atomically with publication, never in a separate post-commit step. */
    public function release(string $objectKey): void;

    /** @return list<string> */
    public function pending(int $limit = 25): array;

    /** Lock and recheck references before cleanup; a successful publication is never deleted. */
    public function discard(string $objectKey, callable $delete): void;

    /** Like discard, but also recheck expiration under lock for background maintenance. */
    public function cleanupExpired(string $objectKey, callable $delete): void;
}
