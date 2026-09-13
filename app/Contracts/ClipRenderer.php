<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\RenderedClipArtifacts;
use App\Media\RenderClipRequest;

interface ClipRenderer
{
    public function render(RenderClipRequest $request): RenderedClipArtifacts;
}
