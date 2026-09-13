<?php

declare(strict_types=1);

namespace App\Media\Subtitles;

use InvalidArgumentException;

final class TranscriptValidator
{
    public static function fromArray(array $data, int $durationMs): Transcript
    {
        if (count($data) !== 2 || !isset($data['language'], $data['cues']) || !is_string($data['language']) || !is_array($data['cues'])
            || count($data['cues']) > 500 || ($data['cues'] !== [] && array_keys($data['cues']) !== range(0, count($data['cues']) - 1))) {
            throw new InvalidArgumentException('O formato da transcrição é inválido.');
        }
        $cues = [];
        foreach ($data['cues'] as $cue) {
            if (!is_array($cue) || array_diff(array_keys($cue), ['start_ms', 'end_ms', 'text', 'words']) !== []
                || !isset($cue['start_ms'], $cue['end_ms'], $cue['text']) || !is_int($cue['start_ms']) || !is_int($cue['end_ms']) || !is_string($cue['text'])
                || (array_key_exists('words', $cue) && !is_array($cue['words']))) {
                throw new InvalidArgumentException('O formato de uma legenda é inválido.');
            }
            $cues[] = new SubtitleCue($cue['start_ms'], $cue['end_ms'], $cue['text'], $cue['words'] ?? []);
        }
        return new Transcript($data['language'], $cues, $durationMs);
    }
}
