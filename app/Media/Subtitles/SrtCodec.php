<?php

declare(strict_types=1);

namespace App\Media\Subtitles;

use InvalidArgumentException;

final class SrtCodec
{
    public static function parse(string $input, int $durationMs): Transcript
    {
        if (strlen($input) > 262144 || !mb_check_encoding($input, 'UTF-8')) {
            throw new InvalidArgumentException('O arquivo SRT excede o limite ou não está em UTF-8.');
        }
        $input = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $input));
        $input = trim($input, "\n");
        $cues = [];
        if ($input !== '') {
            foreach (preg_split('/\n[ \t]*\n/', $input) as $index => $block) {
                $lines = explode("\n", $block);
                if (count($lines) < 3 || $lines[0] !== (string) ($index + 1)
                    || preg_match('/^(\d{2}):([0-5]\d):([0-5]\d),(\d{3}) --> (\d{2}):([0-5]\d):([0-5]\d),(\d{3})$/D', $lines[1], $matches) !== 1) {
                    throw new InvalidArgumentException('Revise a numeração e os tempos do SRT.');
                }
                $start = (((int) $matches[1] * 60 + (int) $matches[2]) * 60 + (int) $matches[3]) * 1000 + (int) $matches[4];
                $end = (((int) $matches[5] * 60 + (int) $matches[6]) * 60 + (int) $matches[7]) * 1000 + (int) $matches[8];
                $cues[] = new SubtitleCue($start, $end, implode("\n", array_slice($lines, 2)));
            }
        }
        return new Transcript('und', $cues, $durationMs);
    }

    public static function format(Transcript $transcript): string
    {
        $blocks = [];
        foreach ($transcript->cues() as $index => $cue) {
            $blocks[] = ($index + 1) . "\n" . self::timestamp($cue->startMs()) . ' --> ' . self::timestamp($cue->endMs()) . "\n" . $cue->text();
        }
        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";
    }

    private static function timestamp(int $time): string
    {
        return sprintf('%02d:%02d:%02d,%03d', intdiv($time, 3600000), intdiv($time, 60000) % 60, intdiv($time, 1000) % 60, $time % 1000);
    }
}
