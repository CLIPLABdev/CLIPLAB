<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class ProjectSource
{
    private int $id;
    private int $projectId;
    private string $storageDisk;
    private string $objectKey;
    private string $mimeType;
    private ?int $width;
    private ?int $height;

    public function __construct(
        int $id,
        int $projectId,
        string $storageDisk,
        string $objectKey,
        string $mimeType,
        ?int $width = null,
        ?int $height = null
    )
    {
        if ($id <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Source and project ids must be positive.');
        }
        if (trim($storageDisk) === '' || trim($objectKey) === '' || trim($mimeType) === '') {
            throw new InvalidArgumentException('Storage disk, object key and MIME type must not be empty.');
        }
        if (($width !== null && $width <= 0) || ($height !== null && $height <= 0)) {
            throw new InvalidArgumentException('Source geometry must contain positive dimensions.');
        }
        $this->id = $id;
        $this->projectId = $projectId;
        $this->storageDisk = $storageDisk;
        $this->objectKey = $objectKey;
        $this->mimeType = $mimeType;
        $this->width = $width;
        $this->height = $height;
    }

    public function id(): int { return $this->id; }
    public function projectId(): int { return $this->projectId; }
    public function storageDisk(): string { return $this->storageDisk; }
    public function objectKey(): string { return $this->objectKey; }
    public function mimeType(): string { return $this->mimeType; }
    public function width(): ?int { return $this->width; }
    public function height(): ?int { return $this->height; }
    public function hasUsableGeometry(): bool { return $this->width !== null && $this->height !== null; }
}
