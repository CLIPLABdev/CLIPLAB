<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Internal boundary around the HTTP client used for remote media imports.
 * The callback must be invoked only with received response-body bytes.
 */
interface DownloadTransport
{
    /** @param callable(string): void $onChunk */
    public function download(DownloadRequest $request, callable $onChunk): DownloadResponse;
}
