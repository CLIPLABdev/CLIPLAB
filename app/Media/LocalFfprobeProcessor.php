<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\MediaProcessor;
use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use JsonException;

final class LocalFfprobeProcessor implements MediaProcessor
{
    public function __construct(
        private PrivateStorage $storage,
        private ProcessRunner $runner,
        private string $ffprobeBinary,
        private int $timeoutSeconds,
        private int $outputLimitBytes
    ) {
        if (trim($ffprobeBinary) === '' || $timeoutSeconds < 1 || $outputLimitBytes < 1) {
            throw new \InvalidArgumentException('FFprobe process configuration is invalid.');
        }
        // Windows proc_open has no Job Object. Production invokes only direct ffprobe,
        // whose fixed argv below does not launch a shell or a helper process tree.
        $binaryName = strtolower(basename(str_replace('\\', '/', $ffprobeBinary)));
        if (DIRECTORY_SEPARATOR === '\\' && !in_array($binaryName, ['ffprobe', 'ffprobe.exe'], true)) {
            throw new \InvalidArgumentException('FFprobe process configuration is invalid.');
        }
    }

    public function inspect(ProjectSource $source): MediaMetadata
    {
        if ($source->storageDisk() !== 'local') {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        $path = $this->storage->absolutePath($source->objectKey());
        $result = $this->runner->run([
            $this->ffprobeBinary,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ], $this->timeoutSeconds, $this->outputLimitBytes);
        if (strlen($result->stdout) + strlen($result->stderr) > $this->outputLimitBytes) {
            throw new ProcessExecutionException('process_output_limit');
        }
        if ($result->exitCode !== 0) {
            throw new ProcessExecutionException('process_failed');
        }

        try {
            $payload = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        if (!is_array($payload) || !is_array($payload['format'] ?? null) || !is_array($payload['streams'] ?? null)) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }

        $video = $this->firstStream($payload['streams'], 'video');
        if ($video === null) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        $audio = $this->firstStream($payload['streams'], 'audio');
        $duration = $this->duration($payload['format']['duration'] ?? null);
        $width = $this->dimension($video['width'] ?? null);
        $height = $this->dimension($video['height'] ?? null);
        $videoCodec = $this->codec($video['codec_name'] ?? null);
        $audioCodec = $audio === null ? null : $this->codec($audio['codec_name'] ?? null);

        return new MediaMetadata($duration->secondsCeil(), $width, $height, $videoCodec, $audioCodec, $audioCodec !== null, $duration->millisecondsFloor());
    }

    /** @param list<mixed> $streams @return array<string, mixed>|null */
    private function firstStream(array $streams, string $type): ?array
    {
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }
        return null;
    }

    private function duration(mixed $value): MediaDuration
    {
        try {
            $duration = MediaDuration::fromFfprobe($value);
        } catch (\InvalidArgumentException) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        // Ingest keeps its one-second minimum; output verification may inspect shorter streams.
        if ($duration->millisecondsFloor() < 1000) throw MediaValidationException::withCode('invalid_media_metadata');
        return $duration;
    }

    private function dimension(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        $dimension = (int) $value;
        if ($dimension < 1 || $dimension > 16384) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        return $dimension;
    }

    private function codec(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $value) !== 1) {
            throw MediaValidationException::withCode('invalid_media_metadata');
        }
        return $value;
    }
}
