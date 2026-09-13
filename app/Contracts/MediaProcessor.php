<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\MediaMetadata;
use App\Media\ProjectSource;

interface MediaProcessor
{
    public function inspect(ProjectSource $source): MediaMetadata;
}
