<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Converte o campo "timeRanges" da OpusClip (trechos originais do corte, em segundos)
 * na janela [início, fim] do vídeo de origem. Aceita os formatos mais comuns:
 * [[12.5, 40]], [{"start": 12.5, "end": 40}], ["00:00:12.5-00:00:40"], [12.5, 40].
 */
final class OpusClipTimeRanges
{
    /** @return array{0: float, 1: float}|null */
    public static function sourceWindow(mixed $ranges): ?array
    {
        if (!is_array($ranges) || $ranges === []) {
            return null;
        }
        // Um único par numérico plano: [inicio, fim]
        if (count($ranges) === 2 && self::seconds($ranges[0] ?? null) !== null && self::seconds($ranges[1] ?? null) !== null
            && !is_array($ranges[0]) && !is_string($ranges[0])) {
            $ranges = [[$ranges[0], $ranges[1]]];
        }

        $start = null;
        $end = null;
        foreach ($ranges as $range) {
            $pair = self::pair($range);
            if ($pair === null) {
                continue;
            }
            $start = $start === null ? $pair[0] : min($start, $pair[0]);
            $end = $end === null ? $pair[1] : max($end, $pair[1]);
        }
        if ($start === null || $end === null || $end <= $start || $start < 0 || $end > 86400) {
            return null;
        }

        return [round($start, 3), round($end, 3)];
    }

    /** @return array{0: float, 1: float}|null */
    private static function pair(mixed $range): ?array
    {
        if (is_array($range)) {
            if (array_is_list($range) && count($range) >= 2) {
                $a = self::seconds($range[0]);
                $b = self::seconds($range[1]);
            } else {
                $a = self::seconds($range['start'] ?? $range['startTime'] ?? $range['from'] ?? $range['begin'] ?? null);
                $b = self::seconds($range['end'] ?? $range['endTime'] ?? $range['to'] ?? $range['finish'] ?? null);
                if ($a === null && isset($range['startMs']) && is_numeric($range['startMs'])) {
                    $a = (float) $range['startMs'] / 1000;
                }
                if ($b === null && isset($range['endMs']) && is_numeric($range['endMs'])) {
                    $b = (float) $range['endMs'] / 1000;
                }
            }
        } elseif (is_string($range) && preg_match('/^\s*([0-9:.]+)\s*[-–,]\s*([0-9:.]+)\s*$/', $range, $match) === 1) {
            $a = self::seconds($match[1]);
            $b = self::seconds($match[2]);
        } else {
            return null;
        }

        return $a !== null && $b !== null && $b > $a ? [$a, $b] : null;
    }

    private static function seconds(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', $value, $match) === 1) {
            return (int) ($match[1] !== '' ? $match[1] : 0) * 3600 + (int) $match[2] * 60 + (float) $match[3];
        }

        return null;
    }
}
