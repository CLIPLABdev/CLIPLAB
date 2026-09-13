<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\BudgetedYoutubeMediaResolver;
use App\Exceptions\MediaValidationException;
use App\Process\ProcessRunner;
use App\Process\ProcessExecutionException;

final class YtDlpYoutubeResolver implements BudgetedYoutubeMediaResolver
{
    public function __construct(
        private ProcessRunner $runner,
        private DirectUrlValidator $directUrls,
        private string $binary = 'yt-dlp',
        private int $timeoutSeconds = 60,
        private int $outputLimitBytes = 1048576,
        private ?string $javascriptRuntime = null,
        private bool $forceIpv4 = true,
        private $report = null
    ) {
        if (trim($binary) === '' || $timeoutSeconds < 1 || $outputLimitBytes < 1024) {
            throw new \InvalidArgumentException('YouTube resolver configuration is invalid.');
        }
    }

    public function resolve(ValidatedYoutubeUrl $url): ResolvedYoutubeMedia
    {
        return $this->resolveMetadata($url, null);
    }

    public function resolveWithinLimit(ValidatedYoutubeUrl $url, int $maxBytes): ResolvedYoutubeMedia
    {
        if ($maxBytes < 1) {
            throw MediaValidationException::withCode('media_too_large');
        }
        return $this->resolveMetadata($url, $maxBytes);
    }

    private function resolveMetadata(ValidatedYoutubeUrl $url, ?int $maxBytes): ResolvedYoutubeMedia
    {
        $stats = ['exit' => null, 'bytes' => 0];
        try {
            $media = $this->performResolution($url, $maxBytes, $stats);
            $this->diagnostic($url, 'metadata_validated', $stats['exit'], $stats['bytes']);
            return $media;
        } catch (MediaValidationException $exception) {
            $this->diagnostic($url, $exception->publicCode(), $stats['exit'], $stats['bytes']);
            throw $exception;
        }
    }

    private function performResolution(ValidatedYoutubeUrl $url, ?int $maxBytes, array &$stats): ResolvedYoutubeMedia
    {
        $socketTimeout = (string) min(20, $this->timeoutSeconds);
        $command = [
            $this->binary,
            '--ignore-config',
            '--no-plugin-dirs',
            '--no-cache-dir',
            '--no-js-runtimes',
            '--no-remote-components',
            '--no-playlist',
            '--skip-download',
            '--print', '%(.{_type,extractor_key,id,availability,is_live,live_status,age_limit,has_drm,duration,ext,protocol,vcodec,acodec,url,filesize,filesize_approx,language,format_id,requested_formats,formats})j',
            '--no-warnings',
            '--socket-timeout', $socketTimeout,
            '--retries', '1',
            '--fragment-retries', '1',
            '--extractor-retries', '1',
            '--use-extractors', 'youtube',
            '--format', 'best[ext=mp4][vcodec!=none][acodec!=none]/bestvideo[ext=mp4][vcodec^=avc1]+bestaudio[ext=m4a]',
        ];
        if (is_string($this->javascriptRuntime) && trim($this->javascriptRuntime) !== '') {
            $command[] = '--js-runtimes';
            $command[] = trim($this->javascriptRuntime);
        }
        if ($this->forceIpv4) {
            $command[] = '--force-ipv4';
        }
        $command[] = '--';
        $command[] = $url->url();

        try {
            $result = $this->runner->run($command, $this->timeoutSeconds, $this->outputLimitBytes);
            $stats = ['exit' => $result->exitCode, 'bytes' => strlen($result->stdout)];
        } catch (ProcessExecutionException $exception) {
            $code = match ($exception->publicCode()) {
                'process_timeout' => 'remote_timeout',
                'process_unavailable' => 'youtube_import_unavailable',
                'process_output_limit' => 'youtube_metadata_limit',
                default => 'youtube_video_unavailable',
            };
            throw MediaValidationException::withCode($code);
        }
        if ($result->exitCode !== 0) {
            $code = YoutubeFailureClassifier::fromStderr($result->stderr);
            throw MediaValidationException::withCode($code);
        }

        try {
            $metadata = json_decode($result->stdout, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw MediaValidationException::withCode('youtube_response_invalid');
        }
        if (!is_array($metadata) || !$this->commonMetadataIsAllowed($metadata, $url)) {
            throw MediaValidationException::withCode('youtube_response_invalid');
        }

        if ($maxBytes !== null) {
            $metadata = $this->withinBudget($metadata, $maxBytes);
        }

        if ($this->progressiveMetadataIsAllowed($metadata)) {
            return new ResolvedYoutubeMedia($this->validatedCdnUrl((string) $metadata['url']));
        }

        $formats = $metadata['requested_formats'] ?? null;
        if (!is_array($formats) || count($formats) !== 2
            || !is_array($formats[0] ?? null) || !is_array($formats[1] ?? null)
            || !$this->trackMetadataIsAllowed($formats[0], 'video')
            || !$this->trackMetadataIsAllowed($formats[1], 'audio')) {
            throw MediaValidationException::withCode('youtube_response_invalid');
        }

        return ResolvedYoutubeMedia::adaptive(
            new ResolvedYoutubeTrack($this->validatedCdnUrl((string) $formats[0]['url']), 'video', 'mp4', 'video/mp4'),
            new ResolvedYoutubeTrack($this->validatedCdnUrl((string) $formats[1]['url']), 'audio', 'm4a', 'audio/mp4')
        );
    }

    private function validatedCdnUrl(string $directUrl): ValidatedRemoteUrl
    {
        $parts = parse_url($directUrl);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if (isset($parts['port']) || ($host !== 'googlevideo.com' && !str_ends_with($host, '.googlevideo.com'))) {
            throw MediaValidationException::withCode('youtube_response_invalid');
        }

        return $this->directUrls->validate($directUrl);
    }

    private function diagnostic(ValidatedYoutubeUrl $url, string $code, ?int $exit = null, int $bytes = 0): void
    {
        if (!is_callable($this->report)) {
            return;
        }
        try {
            ($this->report)(['video_id' => $url->videoId(), 'result_code' => $code,
                'exit_code' => $exit, 'output_bytes' => $bytes, 'limit_bytes' => $this->outputLimitBytes]);
        } catch (\Throwable) {
            // Diagnostics must never interrupt an import. No provider output is logged.
        }
    }

    private function estimatedBytes(array $format): ?int
    {
        foreach (['filesize', 'filesize_approx'] as $key) {
            $value = $format[$key] ?? null;
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_float($value) && is_finite($value) && $value > 0) {
                return $value >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) ceil($value);
            }
        }
        return null;
    }

    private function withinBudget(array $metadata, int $maxBytes): array
    {
        $progressive = $this->progressiveMetadataIsAllowed($metadata);
        $selected = $metadata['requested_formats'] ?? [];
        $adaptive = is_array($selected) && count($selected) === 2
            && is_array($selected[0] ?? null) && is_array($selected[1] ?? null)
            && $this->trackMetadataIsAllowed($selected[0], 'video') && $this->trackMetadataIsAllowed($selected[1], 'audio');
        if (!$progressive && !$adaptive) {
            return $metadata; // Existing strict validation rejects malformed provider data.
        }
        $inputBudget = $maxBytes - min(16777216, max(1048576, intdiv($maxBytes, 20)), max(1, intdiv($maxBytes, 4)));
        if ($progressive) {
            $size = $this->estimatedBytes($metadata);
            if ($size === null || $size <= $maxBytes) {
                return $metadata; // Unknown sizes remain bounded by the streaming downloader.
            }
        } else {
            $videoBytes = $this->estimatedBytes($selected[0]);
            $audioBytes = $this->estimatedBytes($selected[1]);
            if ($videoBytes === null || $audioBytes === null || $videoBytes + $audioBytes <= $inputBudget) {
                return $metadata;
            }
        }
        $formats = is_array($metadata['formats'] ?? null) ? array_reverse($metadata['formats']) : [];
        foreach ($formats as $format) {
            if (!is_array($format) || (array_key_exists('has_drm', $format) && $format['has_drm'] !== false)) {
                continue;
            }
            $size = $this->estimatedBytes($format);
            if ($size === null) {
                continue;
            }
            if ($adaptive && $this->trackMetadataIsAllowed($format, 'video')
                && $size + ($this->estimatedBytes($selected[1]) ?? PHP_INT_MAX) <= $inputBudget) {
                $metadata['requested_formats'] = [$format, $selected[1]]; // Preserve original audio/language.
                unset($metadata['url']);
                return $metadata;
            }
            if ($progressive && $this->progressiveMetadataIsAllowed($format) && $size <= $maxBytes
                && ($format['language'] ?? null) === ($metadata['language'] ?? null)) {
                return array_replace($metadata, $format);
            }
        }
        throw MediaValidationException::withCode('media_too_large');
    }

    /** @param array<string, mixed> $metadata */
    private function commonMetadataIsAllowed(array $metadata, ValidatedYoutubeUrl $url): bool
    {
        return (!array_key_exists('_type', $metadata) || $metadata['_type'] === 'video')
            && ($metadata['extractor_key'] ?? null) === 'Youtube'
            && ($metadata['id'] ?? null) === $url->videoId()
            && ($metadata['availability'] ?? null) === 'public'
            && ($metadata['is_live'] ?? null) === false
            && ($metadata['live_status'] ?? null) === 'not_live'
            && is_int($metadata['age_limit'] ?? null)
            && $metadata['age_limit'] === 0
            && (!array_key_exists('has_drm', $metadata) || $metadata['has_drm'] === false);
    }

    /** @param array<string, mixed> $metadata */
    private function progressiveMetadataIsAllowed(array $metadata): bool
    {
        return ($metadata['ext'] ?? null) === 'mp4'
            && ($metadata['protocol'] ?? null) === 'https'
            && is_string($metadata['vcodec'] ?? null)
            && $metadata['vcodec'] !== ''
            && $metadata['vcodec'] !== 'none'
            && is_string($metadata['acodec'] ?? null)
            && $metadata['acodec'] !== ''
            && $metadata['acodec'] !== 'none'
            && is_string($metadata['url'] ?? null)
            && $metadata['url'] !== '';
    }

    /** @param array<string, mixed> $format */
    private function trackMetadataIsAllowed(array $format, string $kind): bool
    {
        $isVideo = $kind === 'video';
        return ($format['ext'] ?? null) === ($isVideo ? 'mp4' : 'm4a')
            && ($format['protocol'] ?? null) === 'https'
            && (!array_key_exists('has_drm', $format) || $format['has_drm'] === false)
            && is_string($format['url'] ?? null)
            && $format['url'] !== ''
            && is_string($format['vcodec'] ?? null)
            && ($isVideo ? str_starts_with($format['vcodec'], 'avc1') : $format['vcodec'] === 'none')
            && is_string($format['acodec'] ?? null)
            && ($isVideo ? $format['acodec'] === 'none' : str_starts_with($format['acodec'], 'mp4a'));
    }
}
