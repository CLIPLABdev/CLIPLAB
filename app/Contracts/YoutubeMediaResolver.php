<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\ResolvedYoutubeMedia;
use App\Media\ValidatedYoutubeUrl;

interface YoutubeMediaResolver
{
    public function resolve(ValidatedYoutubeUrl $url): ResolvedYoutubeMedia;
}
