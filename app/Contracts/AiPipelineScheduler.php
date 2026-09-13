<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Ai\AiAnalysisReceipt;

interface AiPipelineScheduler
{
    public function schedule(int $projectId, int $sourceId, int $durationSeconds): AiAnalysisReceipt;
}
