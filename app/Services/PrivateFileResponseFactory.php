<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Response;
use RuntimeException;

final class PrivateFileResponseFactory
{
    private const CHUNK_SIZE = 1048576;

    public function download(string $absolutePath, string $downloadName, int $expectedSize): Response
    {
        $safeName = $this->safeDownloadName($downloadName);

        return $this->response($absolutePath, $expectedSize, 'video/mp4')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"');
    }

    public function thumbnail(string $absolutePath, int $expectedSize): Response
    {
        return $this->response($absolutePath, $expectedSize, 'image/jpeg');
    }

    private function response(string $absolutePath, int $expectedSize, string $contentType): Response
    {
        $this->assertReadableWithExpectedSize($absolutePath, $expectedSize);

        return Response::stream(function () use ($absolutePath, $expectedSize): void {
            $handle = @fopen($absolutePath, 'rb');

            if (!is_resource($handle)) {
                throw $this->genericFileException();
            }

            try {
                $bytesSent = 0;

                while (!feof($handle)) {
                    $chunk = fread($handle, self::CHUNK_SIZE);

                    if ($chunk === false) {
                        throw $this->genericFileException();
                    }

                    $bytesSent += strlen($chunk);
                    echo $chunk;
                }

                if ($bytesSent !== $expectedSize) {
                    throw $this->genericFileException();
                }
            } finally {
                fclose($handle);
            }
        })
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) $expectedSize)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function assertReadableWithExpectedSize(string $absolutePath, int $expectedSize): void
    {
        clearstatcache(true, $absolutePath);

        if ($expectedSize < 0 || !is_file($absolutePath) || !is_readable($absolutePath)) {
            throw $this->genericFileException();
        }

        $size = filesize($absolutePath);

        if ($size === false || $size !== $expectedSize) {
            throw $this->genericFileException();
        }

        $handle = @fopen($absolutePath, 'rb');

        if (!is_resource($handle)) {
            throw $this->genericFileException();
        }

        fclose($handle);
    }

    private function safeDownloadName(string $downloadName): string
    {
        $name = str_replace(["\r", "\n"], '', $downloadName);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';

        if ($name === '' || $name === '.' || $name === '..') {
            return 'clip.mp4';
        }

        return $name;
    }

    private function genericFileException(): RuntimeException
    {
        return new RuntimeException('Unable to prepare private file response.');
    }
}
