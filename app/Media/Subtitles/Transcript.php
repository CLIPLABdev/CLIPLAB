<?php

declare(strict_types=1);

namespace App\Media\Subtitles;

use InvalidArgumentException;

final class Transcript
{
    private string $language;
    private array $cues;
    private int $durationMs;

    public function __construct(string $language, array $cues, int $durationMs)
    {
        if ($durationMs < 1 || $durationMs > 180000 || count($cues) > 500
            || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) !== 1 || strlen($language) > 35
            || ($cues !== [] && array_keys($cues) !== range(0, count($cues) - 1))) {
            throw new InvalidArgumentException('A transcrição é inválida.');
        }
        $previous = 0;
        $wordCount = 0;
        foreach ($cues as $cue) {
            if (!$cue instanceof SubtitleCue || $cue->startMs() < $previous || $cue->endMs() > $durationMs) {
                throw new InvalidArgumentException('As legendas se sobrepõem ou ultrapassam o corte.');
            }
            $previous = $cue->endMs();
            $wordCount += max(count($cue->words()), count(preg_split('/\s+/u', trim($cue->text()), -1, PREG_SPLIT_NO_EMPTY)));
        }
        if ($wordCount > 6000) {
            throw new InvalidArgumentException('A transcrição excede o limite de palavras.');
        }
        $this->language = $language;
        $this->cues = $cues;
        $this->durationMs = $durationMs;
    }

    public function language(): string { return $this->language; }
    /** @return list<SubtitleCue> */
    public function cues(): array { return $this->cues; }
    public function durationMs(): int { return $this->durationMs; }
    public function toArray(): array
    {
        return ['language' => $this->language, 'cues' => array_map(static fn (SubtitleCue $cue): array => $cue->toArray(), $this->cues)];
    }
}
