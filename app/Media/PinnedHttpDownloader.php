<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Storage\PrivateStagingArea;
use App\Storage\PrivateStagingFile;
use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;

final class PinnedHttpDownloader
{
    private DownloadTransport $transport;

    public function __construct(
        private DirectUrlValidator $urlValidator,
        private UploadValidator $uploadValidator,
        private int $timeoutSeconds,
        private int $maxRedirects,
        private PrivateStagingArea $stagingArea,
        mixed $transport = null,
        private ?ProcessRunner $mergeRunner = null,
        private string $ffmpegBinary = 'ffmpeg',
        private int $mergeTimeoutSeconds = 120,
        private int $mergeOutputLimitBytes = 1048576,
        private ?YtDlpMediaFetcher $youtubeFetcher = null
    ) {
        if ($timeoutSeconds < 1 || $maxRedirects < 0 || $mergeTimeoutSeconds < 1 || $mergeOutputLimitBytes < 1) {
            throw new \InvalidArgumentException('Invalid download limits.');
        }
        if ($transport === null) {
            $this->transport = new CurlDownloadTransport();
        } elseif ($transport instanceof DownloadTransport) {
            $this->transport = $transport;
        } elseif (is_callable($transport)) {
            $this->transport = new LegacyCallableDownloadTransport($transport);
        } else {
            throw new \InvalidArgumentException('The download transport must implement DownloadTransport.');
        }
    }

    public function downloadStoredUrl(string $url, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject
    {
        return $this->download($this->urlValidator->validate($url), $storage, $objectKey, $maxBytes);
    }

    public function download(ValidatedRemoteUrl $url, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject
    {
        return $this->downloadWithExtension($url, $storage, $objectKey, $maxBytes, null, null);
    }

    public function downloadYoutube(
        ResolvedYoutubeMedia $media,
        PrivateStorage $storage,
        string $objectKey,
        int $maxBytes
    ): StoredObject
    {
        if ($this->youtubeFetcher !== null && $this->youtubeFetcher->supports($media)) {
            return $this->youtubeFetcher->fetch($media, $storage, $objectKey, $maxBytes);
        }
        if ($media->isAdaptive()) {
            return $this->downloadAdaptiveYoutube($media, $storage, $objectKey, $maxBytes);
        }
        return $this->downloadWithExtension($media->url(), $storage, $objectKey, $maxBytes, $media->extension(), 'googlevideo.com');
    }

    private function downloadAdaptiveYoutube(
        ResolvedYoutubeMedia $media,
        PrivateStorage $storage,
        string $objectKey,
        int $maxBytes
    ): StoredObject {
        if ($this->mergeRunner === null || $maxBytes < 2) {
            throw MediaValidationException::withCode('youtube_import_unavailable');
        }
        $deadline = hrtime(true) + ($this->timeoutSeconds * 1000000000);
        $inputBudget = $maxBytes - $this->mergeOverheadReserve($maxBytes);
        if ($inputBudget < 2) {
            throw MediaValidationException::withCode('media_too_large');
        }
        $stagingFiles = [];
        try {
            $tracks = $media->tracks();
            $video = $this->downloadYoutubeTrack($tracks[0], $objectKey, $inputBudget, $deadline);
            $stagingFiles[] = $video;
            $remainingBytes = $inputBudget - $video->sizeBytes();
            if ($remainingBytes < 1) {
                throw MediaValidationException::withCode('media_too_large');
            }
            $audio = $this->downloadYoutubeTrack($tracks[1], $objectKey, $remainingBytes, $deadline);
            $stagingFiles[] = $audio;

            $output = $this->stagingArea->createStaging($objectKey);
            $stagingFiles[] = $output;
            $output->finish();
            $command = [
                $this->ffmpegBinary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-protocol_whitelist', 'file,pipe',
                '-i', $video->path(),
                '-protocol_whitelist', 'file,pipe', '-i', $audio->path(),
                '-map', '0:v:0', '-map', '1:a:0', '-c', 'copy',
                '-fs', (string) $maxBytes, '-movflags', '+faststart', '-f', 'mp4', $output->path(),
            ];
            try {
                $result = $this->mergeRunner->run($command, $this->mergeTimeoutSeconds, $this->mergeOutputLimitBytes);
            } catch (ProcessExecutionException $exception) {
                $code = $exception->publicCode() === 'process_timeout' ? 'remote_timeout' : 'youtube_import_unavailable';
                throw MediaValidationException::withCode($code);
            }
            if ($result->exitCode !== 0) {
                throw MediaValidationException::withCode('invalid_media_container');
            }
            $size = filesize($output->path());
            if (!is_int($size) || $size < 1 || $size >= $maxBytes) {
                throw MediaValidationException::withCode($size !== false && $size >= $maxBytes ? 'media_too_large' : 'invalid_media_container');
            }
            $this->uploadValidator->validatePath($output->path(), 'youtube.mp4', $size);
            $this->stagingArea->discardStaging($video);
            $this->stagingArea->discardStaging($audio);
            $stagingFiles = [$output];
            $stream = @fopen($output->path(), 'rb');
            if ($stream === false) {
                throw MediaValidationException::withCode('storage_write_failed');
            }
            try {
                return $storage->putStream($stream, $objectKey, $maxBytes);
            } finally {
                fclose($stream);
            }
        } finally {
            foreach ($stagingFiles as $staging) {
                $this->stagingArea->discardStaging($staging);
            }
        }
    }

    private function downloadYoutubeTrack(
        ResolvedYoutubeTrack $track,
        string $objectKey,
        int $maxBytes,
        int $deadline
    ): PrivateStagingFile {
        $url = $track->url();
        $redirects = 0;
        while (true) {
            $remaining = (int) ceil(($deadline - hrtime(true)) / 1000000000);
            if ($remaining < 1) {
                throw MediaValidationException::withCode('remote_timeout');
            }
            $staging = $this->stagingArea->createStaging($objectKey);
            try {
                $response = $this->downloadYoutubeRanges($url, $staging, $maxBytes, $deadline);
                $status = $response->status();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('location');
                    if ($redirects >= $this->maxRedirects || $location === null) {
                        throw MediaValidationException::withCode('remote_redirect_rejected');
                    }
                    $url = $this->urlValidator->validate($this->absoluteLocation($url->url(), $location));
                    $this->assertHostSuffix($url, 'googlevideo.com');
                    $redirects++;
                    $this->stagingArea->discardStaging($staging);
                    continue;
                }
                if ($status !== 200) {
                    throw MediaValidationException::withCode('remote_download_failed');
                }
                $this->validateContentLength($response->header('content-length'), $maxBytes);
                $contentType = strtolower(trim(explode(';', $response->header('content-type') ?? '', 2)[0]));
                $mimeAllowed = $track->kind() === 'video'
                    ? $this->uploadValidator->mimeAllowedForExtension($contentType, 'mp4')
                    : in_array($contentType, ['audio/mp4', 'audio/x-m4a', 'application/mp4'], true);
                if (!$mimeAllowed) {
                    throw MediaValidationException::withCode('invalid_media_container');
                }
                $staging->finish();
                if (!$this->isoBmffSignatureMatches($staging->path())) {
                    throw MediaValidationException::withCode('invalid_media_container');
                }
                return $staging;
            } catch (\Throwable $exception) {
                $this->stagingArea->discardStaging($staging);
                if ($exception instanceof MediaValidationException) {
                    throw $exception;
                }
                throw MediaValidationException::withCode('remote_download_failed');
            }
        }
    }

    private function isoBmffSignatureMatches(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $head = fread($handle, 16);
        } finally {
            fclose($handle);
        }
        return is_string($head) && strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp'
            && preg_match('/^[\x20-\x7e]{4}$/', substr($head, 8, 4)) === 1;
    }

    private function downloadWithExtension(
        ValidatedRemoteUrl $url,
        PrivateStorage $storage,
        string $objectKey,
        int $maxBytes,
        ?string $extensionHint,
        ?string $requiredHostSuffix
    ): StoredObject
    {
        if ($maxBytes < 1) {
            throw MediaValidationException::withCode('media_too_large');
        }

        $deadline = hrtime(true) + ($this->timeoutSeconds * 1000000000);
        $redirects = 0;
        while (true) {
            $remaining = (int) ceil(($deadline - hrtime(true)) / 1000000000);
            if ($remaining < 1) {
                throw MediaValidationException::withCode('remote_timeout');
            }
            $staging = $this->stagingArea->createStaging($objectKey);
            try {
                $response = $requiredHostSuffix === 'googlevideo.com'
                    ? $this->downloadYoutubeRanges($url, $staging, $maxBytes, $deadline)
                    : $this->transport->download(
                    new DownloadRequest($url, $remaining, $maxBytes),
                    static function (string $chunk) use ($staging, $maxBytes): void {
                        $staging->write($chunk, $maxBytes);
                    }
                );
                $status = $response->status();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('location');
                    if ($redirects >= $this->maxRedirects || $location === null) {
                        throw MediaValidationException::withCode('remote_redirect_rejected');
                    }
                    $url = $this->urlValidator->validate($this->absoluteLocation($url->url(), $location));
                    if ($requiredHostSuffix !== null) {
                        $this->assertHostSuffix($url, $requiredHostSuffix);
                    }
                    $redirects++;
                    continue;
                }
                if ($status !== 200) {
                    throw MediaValidationException::withCode('remote_download_failed');
                }
                $this->validateContentLength($response->header('content-length'), $maxBytes);
                $extension = $extensionHint ?? $this->extensionFor($url);
                $contentType = $response->header('content-type') ?? '';
                if (!$this->uploadValidator->mimeAllowedForExtension($contentType, $extension)) {
                    throw MediaValidationException::withCode('invalid_media_container');
                }
                $staging->finish();
                $validated = $this->uploadValidator->validatePath(
                    $staging->path(),
                    'remote.' . $extension,
                    $staging->sizeBytes()
                );
                if (!$this->uploadValidator->mimeAllowedForExtension($contentType, $validated->extension())) {
                    throw MediaValidationException::withCode('invalid_media_container');
                }
                $stream = @fopen($staging->path(), 'rb');
                if ($stream === false) {
                    throw MediaValidationException::withCode('storage_write_failed');
                }
                try {
                    return $storage->putStream($stream, $objectKey, $maxBytes);
                } finally {
                    fclose($stream);
                }
            } catch (MediaValidationException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw MediaValidationException::withCode('remote_download_failed');
            } finally {
                $this->stagingArea->discardStaging($staging);
            }
        }
    }

    /**
     * YouTube throttles large un-ranged GETs. Fetch bounded contiguous ranges,
     * validating each response before appending any bytes to the media file.
     */
    private function downloadYoutubeRanges(
        ValidatedRemoteUrl $url,
        PrivateStagingFile $staging,
        int $maxBytes,
        int $deadline
    ): DownloadResponse {
        $this->assertHostSuffix($url, 'googlevideo.com');
        $offset = 0;
        $total = null;
        $mime = null;
        $redirects = 0;
        while (true) {
            $remaining = (int) ceil(($deadline - hrtime(true)) / 1000000000);
            if ($remaining < 1) {
                throw MediaValidationException::withCode('remote_timeout');
            }
            $requestBytes = min(10485760, ($total ?? $maxBytes) - $offset);
            if ($requestBytes < 1) {
                throw MediaValidationException::withCode('media_too_large');
            }
            $end = $offset + $requestBytes - 1;
            $body = '';
            $response = $this->transport->download(
                new DownloadRequest($url, $remaining, $requestBytes, $offset, $end),
                static function (string $chunk) use (&$body, $requestBytes): void {
                    if (strlen($chunk) > $requestBytes - strlen($body)) {
                        throw MediaValidationException::withCode('media_too_large');
                    }
                    $body .= $chunk;
                }
            );
            if (hrtime(true) > $deadline) {
                throw MediaValidationException::withCode('remote_timeout');
            }
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('location');
                if ($redirects >= $this->maxRedirects || $location === null) {
                    throw MediaValidationException::withCode('remote_redirect_rejected');
                }
                $url = $this->urlValidator->validate($this->absoluteLocation($url->url(), $location));
                $this->assertHostSuffix($url, 'googlevideo.com');
                $redirects++;
                continue;
            }
            $length = strlen($body);
            $declaredLength = $response->header('content-length');
            $this->validateContentLength($declaredLength, $requestBytes);
            $contentType = strtolower(trim($response->header('content-type') ?? ''));
            $encoding = strtolower($response->header('content-encoding') ?? 'identity');
            if ($length < 1 || ($declaredLength !== null && (int) $declaredLength !== $length)
                || ($mime !== null && $contentType !== $mime) || $encoding !== 'identity') {
                throw MediaValidationException::withCode('remote_download_failed');
            }
            $mime = $contentType;

            // Small complete responses can legitimately ignore the initial range.
            // A later 200 must never be appended to an already partial download.
            if ($response->status() === 200 && $offset === 0 && $response->header('content-range') === null) {
                $staging->write($body, $maxBytes);
                return new DownloadResponse(200, ['content-type' => $mime, 'content-length' => (string) $length]);
            }
            if ($response->status() !== 206
                || preg_match('/^bytes (0|[1-9][0-9]*)-(0|[1-9][0-9]*)\/([1-9][0-9]*)$/D', $response->header('content-range') ?? '', $range) !== 1) {
                throw MediaValidationException::withCode('remote_download_failed');
            }
            // Compare the decimal total before casting so oversized values cannot overflow.
            $this->validateContentLength($range[3], $maxBytes);
            $responseTotal = (int) $range[3];
            $expectedEnd = min($end, $responseTotal - 1);
            if ($range[1] !== (string) $offset || $range[2] !== (string) $expectedEnd
                || $length !== $expectedEnd - $offset + 1
                || ($total !== null && $total !== $responseTotal)) {
                throw MediaValidationException::withCode('remote_download_failed');
            }
            $total = $responseTotal;
            $staging->write($body, $maxBytes);
            $offset += $length;
            if ($offset === $total) {
                return new DownloadResponse(200, ['content-type' => $mime, 'content-length' => (string) $total]);
            }
        }
    }

    private function validateContentLength(?string $length, int $maxBytes): void
    {
        if ($length === null) {
            return;
        }
        if ($length === '' || preg_match('/^(?:0|[1-9][0-9]*)$/', $length) !== 1) {
            throw MediaValidationException::withCode('remote_content_length');
        }
        $limit = (string) $maxBytes;
        if (strlen($length) > strlen($limit) || (strlen($length) === strlen($limit) && strcmp($length, $limit) > 0)) {
            throw MediaValidationException::withCode('media_too_large');
        }
    }

    private function extensionFor(ValidatedRemoteUrl $url): string
    {
        $path = parse_url($url->url(), PHP_URL_PATH);
        if (!is_string($path)) {
            throw MediaValidationException::withCode('invalid_media_container');
        }
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw MediaValidationException::withCode('invalid_media_container');
        }
        return $extension;
    }

    private function assertHostSuffix(ValidatedRemoteUrl $url, string $suffix): void
    {
        $host = strtolower($url->host());
        $parts = parse_url($url->url());
        if (!is_array($parts) || isset($parts['port'])
            || ($host !== $suffix && !str_ends_with($host, '.' . $suffix))) {
            throw MediaValidationException::withCode('youtube_response_invalid');
        }
    }

    private function mergeOverheadReserve(int $maxBytes): int
    {
        return min(16777216, max(1048576, intdiv($maxBytes, 20)), max(1, intdiv($maxBytes, 4)));
    }

    private function absoluteLocation(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '' || str_contains($location, "\0")) {
            throw MediaValidationException::withCode('unsafe_source_url');
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw MediaValidationException::withCode('unsafe_source_url');
        }
        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }
        $authority = $baseParts['host'];
        if (isset($baseParts['port'])) {
            $authority .= ':' . $baseParts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $baseParts['scheme'] . '://' . $authority . $location;
        }
        $path = (string) ($baseParts['path'] ?? '/');
        $lastSlash = strrpos($path, '/');
        $directory = $lastSlash === false ? '/' : substr($path, 0, $lastSlash + 1);
        return $baseParts['scheme'] . '://' . $authority . $directory . $location;
    }
}
