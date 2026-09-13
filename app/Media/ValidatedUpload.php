<?php

declare(strict_types=1);

namespace App\Media;

final class ValidatedUpload
{
    public function __construct(
        private string $temporaryPath,
        private string $originalName,
        private string $extension,
        private string $mimeType,
        private int $sizeBytes
    ) {
    }

    public function temporaryPath(): string { return $this->temporaryPath; }
    public function originalName(): string { return $this->originalName; }
    public function extension(): string { return $this->extension; }
    public function mimeType(): string { return $this->mimeType; }
    public function sizeBytes(): int { return $this->sizeBytes; }
}
