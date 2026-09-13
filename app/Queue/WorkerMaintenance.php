<?php

declare(strict_types=1);

namespace App\Queue;

interface WorkerMaintenance
{
    public function run(): void;
}
