<?php
declare(strict_types=1);
namespace App\Contracts;
use App\Media\{ResolvedYoutubeMedia, ValidatedYoutubeUrl};
interface BudgetedYoutubeMediaResolver extends YoutubeMediaResolver
{
    public function resolveWithinLimit(ValidatedYoutubeUrl $url, int $maxBytes): ResolvedYoutubeMedia;
}
