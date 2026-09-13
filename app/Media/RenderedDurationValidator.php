<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class RenderedDurationValidator
{
    /** @param array<string,mixed> $metadata */
    public static function validate(array $metadata, float $requestedSeconds): void
    {
        if (!is_finite($requestedSeconds) || $requestedSeconds < 1 || $requestedSeconds > 180
            || !is_array($metadata['format'] ?? null) || !is_array($metadata['streams'] ?? null)) {
            throw new InvalidArgumentException('Rendered duration is invalid.');
        }
        $video = null;
        foreach ($metadata['streams'] as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
                break;
            }
        }
        $rate = $video['avg_frame_rate'] ?? null;
        if (!is_string($rate) || preg_match('/\A([1-9][0-9]{0,9})\/([1-9][0-9]{0,9})\z/D', $rate, $parts) !== 1) {
            throw new InvalidArgumentException('Rendered duration is invalid.');
        }
        $fps = (float)$parts[1] / (float)$parts[2];
        $tolerance = min(0.15, max(0.05, 2 / $fps));
        // Keep both video and container bounded: audio must not conceal an early video EOF.
        foreach ([$video['duration'] ?? null, $metadata['format']['duration'] ?? null] as $duration) {
            $seconds = MediaDuration::fromFfprobe($duration)->millisecondsFloor() / 1000;
            if (abs($requestedSeconds - $seconds) > $tolerance + 0.000000001) {
                throw new InvalidArgumentException('Rendered duration is invalid.');
            }
        }
    }
}
