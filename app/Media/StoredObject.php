<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class StoredObject
{
    private string $objectKey;
    private int $sizeBytes;
    private string $sha256;

    public function __construct(string $objectKey, int $sizeBytes, string $sha256)
    {
        if (trim($objectKey) === '') {
            throw new InvalidArgumentException('Object key must not be empty.');
        }
        if ($sizeBytes < 0) {
            throw new InvalidArgumentException('Object size must not be negative.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('SHA-256 must be a lowercase hexadecimal digest.');
        }

        $this->objectKey = $objectKey;
        $this->sizeBytes = $sizeBytes;
        $this->sha256 = $sha256;
    }

    public function objectKey(): string { return $this->objectKey; }
    public function sizeBytes(): int { return $this->sizeBytes; }
    public function sha256(): string { return $this->sha256; }
}
