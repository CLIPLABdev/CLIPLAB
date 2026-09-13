<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PrivateStorage;
use App\Contracts\SourceArtifactCleanupStore;
use App\Queue\WorkerMaintenance;
use Throwable;

final class SourceMaintenance implements WorkerMaintenance
{
    public function __construct(private SourceArtifactCleanupStore $cleanups,private PrivateStorage $storage)
    {
    }

    public function run(): void
    {
        foreach ($this->cleanups->pending(25) as $key) {
            try {
                $this->cleanups->cleanupExpired($key,fn () => $this->storage->delete($key));
            } catch (Throwable) {
                // The row remains durable; continue the bounded batch and retry on another finite run.
            }
        }
    }
}
