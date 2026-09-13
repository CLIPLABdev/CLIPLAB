<?php

declare(strict_types=1);

namespace App\Services;

use App\Queue\WorkerMaintenance;
use InvalidArgumentException;
use Throwable;

final class CompositeWorkerMaintenance implements WorkerMaintenance
{
    /** @param list<WorkerMaintenance> $items */
    public function __construct(private array $items)
    {
        foreach ($items as $item) if (!$item instanceof WorkerMaintenance) throw new InvalidArgumentException('Every maintenance item must implement WorkerMaintenance.');
    }

    public function run(): void
    {
        $lastError=null;
        foreach ($this->items as $item) {
            try { $item->run(); } catch (Throwable $error) { $lastError=$error; }
        }
        if ($lastError !== null) throw $lastError;
    }
}
