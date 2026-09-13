<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class RenderedClipArtifacts
{
    private string $videoPath;
    private int $videoSizeBytes;
    private string $videoMimeType;
    private string $thumbnailPath;
    private int $thumbnailSizeBytes;
    private string $thumbnailMimeType;

    public function __construct(
        string $videoPath,
        int $videoSizeBytes,
        string $videoMimeType,
        string $thumbnailPath,
        int $thumbnailSizeBytes,
        string $thumbnailMimeType
    ) {
        if (trim($videoPath) === '' || trim($thumbnailPath) === '' || $videoPath === $thumbnailPath) {
            throw new InvalidArgumentException('Rendered artifact paths must be distinct and non-empty.');
        }
        if ($videoSizeBytes < 1 || $thumbnailSizeBytes < 1) {
            throw new InvalidArgumentException('Rendered artifact sizes must be positive.');
        }
        if ($videoMimeType !== 'video/mp4' || $thumbnailMimeType !== 'image/jpeg') {
            throw new InvalidArgumentException('Rendered artifact MIME types are invalid.');
        }

        $this->videoPath = $videoPath;
        $this->videoSizeBytes = $videoSizeBytes;
        $this->videoMimeType = $videoMimeType;
        $this->thumbnailPath = $thumbnailPath;
        $this->thumbnailSizeBytes = $thumbnailSizeBytes;
        $this->thumbnailMimeType = $thumbnailMimeType;
    }

    public function videoPath(): string { return $this->videoPath; }
    public function videoSizeBytes(): int { return $this->videoSizeBytes; }
    public function videoMimeType(): string { return $this->videoMimeType; }
    public function thumbnailPath(): string { return $this->thumbnailPath; }
    public function thumbnailSizeBytes(): int { return $this->thumbnailSizeBytes; }
    public function thumbnailMimeType(): string { return $this->thumbnailMimeType; }

    public function cleanup(): void
    {
        foreach ([$this->videoPath, $this->thumbnailPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
