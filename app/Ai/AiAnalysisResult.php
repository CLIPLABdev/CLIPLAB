<?php

declare(strict_types=1);

namespace App\Ai;

use InvalidArgumentException;

final class AiAnalysisResult
{
    private string $videoSummary;

    /** @var list<AiClipSuggestion> */
    private array $clips;

    /** @param list<AiClipSuggestion> $clips */
    public function __construct(string $videoSummary, array $clips)
    {
        if (trim($videoSummary) === ''
            || mb_strlen($videoSummary, 'UTF-8') > 2000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $videoSummary) !== 0
        ) {
            throw new InvalidArgumentException('AI analysis summary is invalid.');
        }

        $count = count($clips);
        if ($count < 1 || $count > 10) {
            throw new InvalidArgumentException('AI analysis must contain between one and ten clips.');
        }

        $position = 0;
        foreach ($clips as $key => $clip) {
            if ($key !== $position || !$clip instanceof AiClipSuggestion || $clip->index() !== $position) {
                throw new InvalidArgumentException('AI analysis clips must be an ordered, unique list.');
            }
            $position++;
        }

        $this->videoSummary = $videoSummary;
        $this->clips = $clips;
    }

    public function videoSummary(): string
    {
        return $this->videoSummary;
    }

    /** @return list<AiClipSuggestion> */
    public function clips(): array
    {
        return $this->clips;
    }
}
