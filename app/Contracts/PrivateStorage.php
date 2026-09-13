<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\StoredObject;

interface PrivateStorage
{
    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject;

    /** @param resource $stream */
    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject;

    public function absolutePath(string $objectKey): string;

    public function delete(string $objectKey): void;
}
