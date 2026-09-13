<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class MediaMetadata
{
    private int $durationSeconds;
    private int $width;
    private int $height;
    private string $videoCodec;
    private ?string $audioCodec;
    private bool $hasAudio;

    public function __construct(int $durationSeconds, int $width, int $height, string $videoCodec, ?string $audioCodec, bool $hasAudio, private ?int $durationMilliseconds = null)
    {
        if ($durationSeconds < 0) {
            throw new InvalidArgumentException('Media duration must not be negative.');
        }
        if ($durationMilliseconds !== null
            && ($durationMilliseconds < 0 || $durationMilliseconds > $durationSeconds * 1000
                || $durationMilliseconds < ($durationSeconds - 1) * 1000)) {
            throw new InvalidArgumentException('Precise media duration must match accounting duration.');
        }
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Media dimensions must be positive.');
        }
        if (trim($videoCodec) === '') {
            throw new InvalidArgumentException('Video codec must not be empty.');
        }
        if ($hasAudio && ($audioCodec === null || trim($audioCodec) === '')) {
            throw new InvalidArgumentException('Audio codec is required when audio is present.');
        }
        if (!$hasAudio && $audioCodec !== null) {
            throw new InvalidArgumentException('Audio codec must be null when audio is absent.');
        }
        $this->durationSeconds = $durationSeconds;
        $this->width = $width;
        $this->height = $height;
        $this->videoCodec = $videoCodec;
        $this->audioCodec = $audioCodec;
        $this->hasAudio = $hasAudio;
    }

    public function durationSeconds(): int { return $this->durationSeconds; }
    public function durationMilliseconds(): ?int { return $this->durationMilliseconds; }
    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function videoCodec(): string { return $this->videoCodec; }
    public function audioCodec(): ?string { return $this->audioCodec; }
    public function hasAudio(): bool { return $this->hasAudio; }
}
