<?php

declare(strict_types=1);

namespace App\Queue;

final class WorkerReport
{
    public int $claimed = 0;
    public int $completed = 0;
    public int $retried = 0;
    public int $deferred = 0;
    public int $failed = 0;
    public int $operationalErrors = 0;
}
