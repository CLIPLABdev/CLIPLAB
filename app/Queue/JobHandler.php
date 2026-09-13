<?php

declare(strict_types=1);

namespace App\Queue;

interface JobHandler
{
    public function handle(ClaimedJob $job): JobOutcome;
}
