<?php

declare(strict_types=1);

namespace App\Media\Reframe;

use InvalidArgumentException;

final class ReframeKeyframe
{
    private int $atMs;
    private int $centerXMillionths;
    private int $centerYMillionths;
    private string $source;

    public function __construct(int $atMs, float $centerX, float $centerY, string $source)
    {
        if ($atMs < 0) {
            throw new InvalidArgumentException('Reframe keyframe time must be non-negative.');
        }
        if (!is_finite($centerX) || $centerX < 0.0 || $centerX > 1.0) {
            throw new InvalidArgumentException('Reframe keyframe X coordinate must be finite and between zero and one.');
        }
        if (!is_finite($centerY) || $centerY < 0.0 || $centerY > 1.0) {
            throw new InvalidArgumentException('Reframe keyframe Y coordinate must be finite and between zero and one.');
        }
        if ($source !== 'manual' && $source !== 'detected') {
            throw new InvalidArgumentException('Reframe keyframe source is invalid.');
        }

        $this->atMs = $atMs;
        $this->centerXMillionths = (int) round($centerX * 1000000);
        $this->centerYMillionths = (int) round($centerY * 1000000);
        $this->source = $source;
    }

    public function atMs(): int
    {
        return $this->atMs;
    }

    public function centerX(): float
    {
        return $this->centerXMillionths / 1000000;
    }

    public function centerY(): float
    {
        return $this->centerYMillionths / 1000000;
    }

    public function centerXDecimal(): string
    {
        return $this->decimal($this->centerXMillionths);
    }

    public function centerYDecimal(): string
    {
        return $this->decimal($this->centerYMillionths);
    }

    public function source(): string
    {
        return $this->source;
    }

    private function decimal(int $millionths): string
    {
        return intdiv($millionths, 1000000) . '.' . str_pad((string) ($millionths % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
