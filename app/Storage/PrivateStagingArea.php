<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Internal write-ahead area for remote downloads.
 * Promotion remains the responsibility of the stable PrivateStorage contract.
 */
interface PrivateStagingArea
{
    public function createStaging(string $objectKey): PrivateStagingFile;

    public function discardStaging(PrivateStagingFile $staging): void;
}
