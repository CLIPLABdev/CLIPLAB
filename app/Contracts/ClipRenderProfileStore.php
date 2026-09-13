<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\Reframe\ReframePlanResolution;

interface ClipRenderProfileStore
{
    public function resolveForJob(int $clipId, int $renderRevision): ReframePlanResolution;
}
