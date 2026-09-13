<?php

declare(strict_types=1);

namespace App\Media\Reframe;

use InvalidArgumentException;
use JsonException;

final class ReframePlanValidator
{
    private const WS = '[\x20\x09\x0A\x0D]*';
    private const INTEGER = '(?:0|[1-9][0-9]{0,8})';
    private const COORDINATE = '(?:0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)';
    private const MAX_KEYFRAMES_JSON_BYTES = 16384;

    private int $maxDurationSeconds;
    private int $maxKeyframes;
    private string $detectorVersion;

    public function __construct(
        int $maxDurationSeconds = 90,
        int $maxKeyframes = 32,
        string $detectorVersion = ReframePlan::DETECTOR_VERSION
    ) {
        if ($maxDurationSeconds < 1 || $maxDurationSeconds > 180) {
            throw new InvalidArgumentException('Reframe maximum duration must be between one and 180 seconds.');
        }
        if ($maxKeyframes < 1 || $maxKeyframes > 32) {
            throw new InvalidArgumentException('Reframe maximum keyframes must be between one and 32.');
        }
        if ($detectorVersion !== ReframePlan::DETECTOR_VERSION) {
            throw new InvalidArgumentException('Reframe detector version is invalid.');
        }

        $this->maxDurationSeconds = $maxDurationSeconds;
        $this->maxKeyframes = $maxKeyframes;
        $this->detectorVersion = $detectorVersion;
    }

    public function validate(ReframeSubmission $submission, int $durationMs): ReframePlan
    {
        if ($submission->hasServerOwnedFields()) {
            throw new InvalidArgumentException('Server-owned reframe fields are not accepted.');
        }
        if ($durationMs < 0) {
            throw new InvalidArgumentException('Reframe duration must be non-negative.');
        }

        $aspectRatio = AspectRatio::fromString($submission->aspectRatio());
        $mode = $submission->mode();

        if ($mode === 'original') {
            $this->assertOriginalSubmission($submission, $aspectRatio);

            return ReframePlan::original();
        }

        if ($aspectRatio->isOriginal()) {
            throw new InvalidArgumentException('Original aspect ratio only accepts original mode.');
        }
        if ($durationMs > $this->maxDurationSeconds * 1000) {
            throw new InvalidArgumentException('Reframe duration exceeds the configured limit.');
        }

        if ($mode === 'center') {
            $this->assertEmptyFocusAndKeyframes($submission);

            return ReframePlan::center($aspectRatio);
        }
        if ($mode === 'manual') {
            if (!$this->hasEmptyKeyframes($submission->keyframesJson())) {
                throw new InvalidArgumentException('Manual reframe mode does not accept keyframes.');
            }

            return ReframePlan::manual(
                $aspectRatio,
                new ReframeKeyframe(0, $this->coordinate($submission->focusX()), $this->coordinate($submission->focusY()), 'manual')
            );
        }
        if ($mode === 'auto') {
            if ($submission->focusX() !== '' || $submission->focusY() !== '') {
                throw new InvalidArgumentException('Automatic reframe mode does not accept focus coordinates.');
            }

            return ReframePlan::automatic($aspectRatio, $this->detectorVersion, $this->automaticKeyframes($submission->keyframesJson(), $durationMs));
        }

        throw new InvalidArgumentException('Reframe mode is invalid.');
    }

    private function assertOriginalSubmission(ReframeSubmission $submission, AspectRatio $aspectRatio): void
    {
        if (!$aspectRatio->isOriginal()
            || $submission->focusX() !== ''
            || $submission->focusY() !== ''
            || !$this->hasEmptyKeyframes($submission->keyframesJson())) {
            throw new InvalidArgumentException('Original reframe mode accepts no reframe data.');
        }
    }

    private function assertEmptyFocusAndKeyframes(ReframeSubmission $submission): void
    {
        if ($submission->focusX() !== '' || $submission->focusY() !== '' || !$this->hasEmptyKeyframes($submission->keyframesJson())) {
            throw new InvalidArgumentException('Center reframe mode accepts no focus or keyframes.');
        }
    }

    private function hasEmptyKeyframes(string $keyframesJson): bool
    {
        return $keyframesJson === '' || $keyframesJson === '[]';
    }

    /** @return list<ReframeKeyframe> */
    private function automaticKeyframes(string $keyframesJson, int $durationMs): array
    {
        if (strlen($keyframesJson) > self::MAX_KEYFRAMES_JSON_BYTES) {
            throw new InvalidArgumentException('Automatic keyframes JSON is too large.');
        }
        if (preg_match($this->automaticJsonPattern(), $keyframesJson) !== 1) {
            throw new InvalidArgumentException('Automatic keyframes JSON is not canonical.');
        }

        try {
            $decoded = json_decode($keyframesJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Automatic keyframes JSON is invalid.', 0, $exception);
        }

        if (!is_array($decoded) || !$this->isList($decoded) || count($decoded) < 2 || count($decoded) > $this->maxKeyframes) {
            throw new InvalidArgumentException('Automatic keyframes list is invalid.');
        }

        $keyframes = [];
        $previousAtMs = null;
        foreach ($decoded as $item) {
            if (!is_array($item) || array_keys($item) !== ['at_ms', 'center_x', 'center_y']) {
                throw new InvalidArgumentException('Automatic keyframe object is invalid.');
            }
            if (!is_int($item['at_ms'])
                || (!is_int($item['center_x']) && !is_float($item['center_x']))
                || (!is_int($item['center_y']) && !is_float($item['center_y']))) {
                throw new InvalidArgumentException('Automatic keyframe values must use native JSON number types.');
            }

            $atMs = $item['at_ms'];
            if ($previousAtMs !== null && $atMs <= $previousAtMs) {
                throw new InvalidArgumentException('Automatic keyframe times must be strictly increasing.');
            }
            $keyframes[] = new ReframeKeyframe($atMs, (float) $item['center_x'], (float) $item['center_y'], 'detected');
            $previousAtMs = $atMs;
        }

        if ($keyframes[0]->atMs() !== 0 || $keyframes[count($keyframes) - 1]->atMs() !== $durationMs) {
            throw new InvalidArgumentException('Automatic keyframes must cover the complete duration.');
        }

        return $keyframes;
    }

    private function coordinate(string $value): float
    {
        if (preg_match('/\A' . self::COORDINATE . '\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Focus coordinate is not canonical.');
        }

        return (float) $value;
    }

    private function automaticJsonPattern(): string
    {
        $ws = self::WS;
        $item = '\\{' . $ws
            . '"at_ms"' . $ws . ':' . $ws . self::INTEGER . $ws . ','
            . $ws . '"center_x"' . $ws . ':' . $ws . self::COORDINATE . $ws . ','
            . $ws . '"center_y"' . $ws . ':' . $ws . self::COORDINATE
            . $ws . '\\}';

        return '/\\A' . $ws . '\\[(?:' . $item . '(?:' . $ws . ',' . $ws . $item . ')*)?\\]' . $ws . '\\z/D';
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
