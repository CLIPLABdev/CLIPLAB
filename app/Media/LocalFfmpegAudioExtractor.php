<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\ClipAudioExtractor;
use App\Contracts\PrivateStorage;
use App\Exceptions\SubtitleException;
use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use InvalidArgumentException;
use Throwable;

final class LocalFfmpegAudioExtractor implements ClipAudioExtractor
{
    private const MAX_BYTES = 6291456;
    private string $temporaryDirectory;

    public function __construct(
        private PrivateStorage $storage,
        private ProcessRunner $runner,
        private string $ffmpegBinary,
        string $temporaryDirectory,
        private int $timeoutSeconds,
        private int $outputLimitBytes
    ) {
        $resolved = realpath($temporaryDirectory);
        $normalized = $resolved === false ? '' : strtolower(str_replace('\\', '/', $resolved));
        $publicRoots = [];
        foreach (['public', 'public_html'] as $directory) {
            $public = realpath(dirname(__DIR__, 2) . '/' . $directory);
            if ($public !== false) {
                $publicRoots[] = strtolower(str_replace('\\', '/', $public));
            }
        }
        $insidePublicRoot = false;
        foreach ($publicRoots as $publicRoot) {
            if ($normalized === $publicRoot || str_starts_with($normalized, $publicRoot . '/')) {
                $insidePublicRoot = true;
                break;
            }
        }
        $binaryName = strtolower(basename(str_replace('\\', '/', $ffmpegBinary)));
        if (trim($ffmpegBinary) === '' || $resolved === false || !is_dir($resolved) || !is_writable($resolved)
            || $timeoutSeconds < 1 || $outputLimitBytes < 1
            || $insidePublicRoot
            || (DIRECTORY_SEPARATOR === '\\' && !in_array($binaryName, ['ffmpeg', 'ffmpeg.exe'], true))) {
            throw new InvalidArgumentException('Audio extraction configuration is invalid.');
        }
        $this->temporaryDirectory = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /** Returns WAV bytes; no temporary pathname escapes this boundary. */
    public function extract(ProjectSource $source, float $startTime, float $durationSeconds): string
    {
        if ($source->storageDisk() !== 'local' || !is_finite($startTime) || $startTime < 0
            || !is_finite($durationSeconds) || $durationSeconds < 1 || $durationSeconds > 180) {
            throw SubtitleException::withCode('subtitle_audio_invalid');
        }
        $output = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cliplab-audio-' . bin2hex(random_bytes(16)) . '.wav';
        try {
            $input = $this->storage->absolutePath($source->objectKey());
            if (!is_file($input) || !is_readable($input)) {
                throw SubtitleException::withCode('subtitle_audio_invalid');
            }
            $result = $this->runner->run([
                $this->ffmpegBinary, '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-ss', $this->seconds($startTime), '-i', $input, '-t', $this->seconds($durationSeconds),
                '-map', '0:a:0', '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-f', 'wav', $output,
            ], $this->timeoutSeconds, $this->outputLimitBytes);
            if ($result->exitCode !== 0) {
                throw SubtitleException::withCode('subtitle_audio_missing');
            }
            clearstatcache(true, $output);
            $size = is_file($output) ? filesize($output) : false;
            if (!is_int($size) || $size < 44 || $size > self::MAX_BYTES) {
                throw SubtitleException::withCode('subtitle_audio_invalid');
            }
            $bytes = file_get_contents($output, false, null, 0, self::MAX_BYTES + 1);
            if (!is_string($bytes) || strlen($bytes) !== $size || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
                throw SubtitleException::withCode('subtitle_audio_invalid');
            }
            return $bytes;
        } catch (SubtitleException $error) {
            throw $error;
        } catch (ProcessExecutionException) {
            throw SubtitleException::withCode('subtitle_unavailable');
        } catch (Throwable) {
            throw SubtitleException::withCode('subtitle_audio_invalid');
        } finally {
            if (is_file($output)) {
                @unlink($output);
            }
        }
    }

    private function seconds(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
