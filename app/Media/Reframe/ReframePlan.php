<?php

declare(strict_types=1);

namespace App\Media\Reframe;

use InvalidArgumentException;

final class ReframePlan
{
    public const DETECTOR_VERSION = 'tasks-vision-1.0.1/blazeface-short-f16-r1';

    private AspectRatio $aspectRatio;
    private string $mode;
    /** @var list<ReframeKeyframe> */
    private array $keyframes;
    private ?string $detectorVersion;

    /** @param list<ReframeKeyframe> $keyframes */
    private function __construct(AspectRatio $aspectRatio, string $mode, array $keyframes, ?string $detectorVersion)
    {
        $this->aspectRatio = $aspectRatio;
        $this->mode = $mode;
        $this->keyframes = $keyframes;
        $this->detectorVersion = $detectorVersion;
    }

    public static function original(): self
    {
        return new self(AspectRatio::original(), 'original', [], null);
    }

    public static function center(AspectRatio $aspectRatio): self
    {
        self::assertReframeRatio($aspectRatio);

        return new self($aspectRatio, 'center', [], null);
    }

    public static function manual(AspectRatio $aspectRatio, ReframeKeyframe $keyframe): self
    {
        self::assertReframeRatio($aspectRatio);
        if ($keyframe->source() !== 'manual') {
            throw new InvalidArgumentException('Manual reframe plans require a manual keyframe.');
        }
        if ($keyframe->atMs() !== 0) {
            throw new InvalidArgumentException('Manual reframe plans require an initial keyframe.');
        }

        return new self($aspectRatio, 'manual', [$keyframe], null);
    }

    /** @param list<ReframeKeyframe> $keyframes */
    public static function automatic(AspectRatio $aspectRatio, string $detectorVersion, array $keyframes): self
    {
        self::assertReframeRatio($aspectRatio);
        if ($detectorVersion !== self::DETECTOR_VERSION) {
            throw new InvalidArgumentException('Reframe detector version is invalid.');
        }
        if (!self::isList($keyframes) || count($keyframes) < 2 || count($keyframes) > 32) {
            throw new InvalidArgumentException('Automatic reframe plans require between two and 32 keyframes.');
        }
        $previousAtMs = null;
        foreach ($keyframes as $keyframe) {
            if (!$keyframe instanceof ReframeKeyframe || $keyframe->source() !== 'detected') {
                throw new InvalidArgumentException('Automatic reframe plans require detected keyframes.');
            }
            if ($previousAtMs === null && $keyframe->atMs() !== 0) {
                throw new InvalidArgumentException('Automatic reframe plans require an initial keyframe.');
            }
            if ($previousAtMs !== null && $keyframe->atMs() <= $previousAtMs) {
                throw new InvalidArgumentException('Automatic reframe keyframe times must be strictly increasing.');
            }
            $previousAtMs = $keyframe->atMs();
        }

        return new self($aspectRatio, 'auto', $keyframes, $detectorVersion);
    }

    public function aspectRatio(): AspectRatio
    {
        return $this->aspectRatio;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return list<ReframeKeyframe> */
    public function keyframes(): array
    {
        return $this->keyframes;
    }

    public function detectorVersion(): ?string
    {
        return $this->detectorVersion;
    }

    private static function assertReframeRatio(AspectRatio $aspectRatio): void
    {
        if ($aspectRatio->isOriginal()) {
            throw new InvalidArgumentException('Original aspect ratio cannot be reframed.');
        }
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
