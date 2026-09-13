<?php

declare(strict_types=1);

namespace App\Media;

use App\Exceptions\MediaValidationException;

/** @internal Compatibility adapter for Task 2's original injectable callable. */
final class LegacyCallableDownloadTransport implements DownloadTransport
{
    /** @var \Closure(ValidatedRemoteUrl, int): array{status: int, headers: array<string, string>, stream: mixed} */
    private \Closure $transport;

    public function __construct(callable $transport)
    {
        $this->transport = \Closure::fromCallable($transport);
    }

    /** @param callable(string): void $onChunk */
    public function download(DownloadRequest $request, callable $onChunk): DownloadResponse
    {
        $response = ($this->transport)($request->url(), $request->timeoutSeconds());
        if (!is_array($response) || !isset($response['status']) || !is_int($response['status']) && !ctype_digit((string) $response['status'])) {
            throw MediaValidationException::withCode('remote_download_failed');
        }
        $stream = $response['stream'] ?? null;
        if ($stream !== null && (!is_resource($stream) || get_resource_type($stream) !== 'stream')) {
            throw MediaValidationException::withCode('remote_download_failed');
        }
        try {
            if (is_resource($stream)) {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        throw MediaValidationException::withCode('remote_download_failed');
                    }
                    if ($chunk !== '') {
                        $onChunk($chunk);
                    }
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $headers = $response['headers'] ?? [];
        if (!is_array($headers)) {
            throw MediaValidationException::withCode('remote_download_failed');
        }
        try {
            return new DownloadResponse((int) $response['status'], $headers);
        } catch (\InvalidArgumentException $exception) {
            throw MediaValidationException::withCode('remote_download_failed');
        }
    }
}
