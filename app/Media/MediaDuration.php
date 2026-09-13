<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

/** Decimal FFprobe duration; accounting rounds up, EOF truncates to stored precision. */
final class MediaDuration
{
    private function __construct(private int $ceilSeconds, private int $floorMilliseconds) {}

    public static function fromFfprobe(mixed $value): self
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || strlen((string)$value) > 64
            || preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/D', (string)$value, $parts) !== 1) {
            throw new InvalidArgumentException('Media duration is invalid.');
        }
        $wholeText = ltrim($parts[1], '0');
        if (strlen($wholeText) > 5) throw new InvalidArgumentException('Media duration is invalid.');
        $whole = (int)$wholeText;
        $fraction = $parts[2] ?? '';
        $hasFraction = trim($fraction, '0') !== '';
        $ceil = $whole + ($hasFraction ? 1 : 0);
        if ($ceil < 1 || $ceil > 86400) throw new InvalidArgumentException('Media duration is invalid.');

        return new self($ceil, $whole * 1000 + (int)substr(str_pad($fraction, 3, '0'), 0, 3));
    }

    public function secondsCeil(): int { return $this->ceilSeconds; }
    public function millisecondsFloor(): int { return $this->floorMilliseconds; }
}
