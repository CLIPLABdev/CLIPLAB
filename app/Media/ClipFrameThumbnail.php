<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\PrivateStorage;
use App\Process\ProcessRunner;
use Throwable;

/** Gera a capa (JPEG) a partir de um quadro do próprio arquivo do corte. */
final class ClipFrameThumbnail
{
    public function __construct(private ProcessRunner $runner, private string $ffmpegBinary = 'ffmpeg')
    {
    }

    public function fromVideo(PrivateStorage $storage, string $videoObjectKey, string $targetObjectKey): ?StoredObject
    {
        $output = null;
        try {
            $input = $storage->absolutePath($videoObjectKey);
            if (!is_file($input)) {
                return null;
            }
            $output = dirname($input) . DIRECTORY_SEPARATOR . '.thumb-' . bin2hex(random_bytes(12)) . '.jpg';
            foreach (['1', '0'] as $seek) {
                $result = $this->runner->run([
                    $this->ffmpegBinary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                    '-ss', $seek, '-i', $input, '-frames:v', '1', '-vf', 'scale=720:-2', '-q:v', '3', $output,
                ], 60, 65536);
                clearstatcache(true, $output);
                if ($result->exitCode === 0 && is_file($output) && filesize($output) > 0) {
                    break;
                }
            }
            if (!is_file($output) || filesize($output) < 1) {
                return null;
            }
            $stream = fopen($output, 'rb');
            if ($stream === false) {
                return null;
            }
            try {
                return $storage->putStream($stream, $targetObjectKey, 10485760);
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return null;
        } finally {
            if (is_string($output) && is_file($output)) {
                @unlink($output);
            }
        }
    }
}
