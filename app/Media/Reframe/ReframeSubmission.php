<?php

declare(strict_types=1);

namespace App\Media\Reframe;

final class ReframeSubmission
{
    private string $aspectRatio;
    private string $mode;
    private string $focusX;
    private string $focusY;
    private string $keyframesJson;
    private bool $hasServerOwnedFields;

    public function __construct(
        string $aspectRatio,
        string $mode,
        string $focusX,
        string $focusY,
        string $keyframesJson,
        bool $hasServerOwnedFields = false
    ) {
        $this->aspectRatio = $aspectRatio;
        $this->mode = $mode;
        $this->focusX = $focusX;
        $this->focusY = $focusY;
        $this->keyframesJson = $keyframesJson;
        $this->hasServerOwnedFields = $hasServerOwnedFields;
    }

    public static function original(): self
    {
        return new self('original', 'original', '', '', '');
    }

    public function aspectRatio(): string
    {
        return $this->aspectRatio;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function focusX(): string
    {
        return $this->focusX;
    }

    public function focusY(): string
    {
        return $this->focusY;
    }

    public function keyframesJson(): string
    {
        return $this->keyframesJson;
    }

    public function hasServerOwnedFields(): bool
    {
        return $this->hasServerOwnedFields;
    }
}
