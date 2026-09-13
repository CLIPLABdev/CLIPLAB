<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Response;
use App\Http\SingleByteRange;
use App\Http\UnsatisfiableByteRange;
use Closure;
use RuntimeException;
use Throwable;

final class PrivateRangeResponseFactory
{
    private const CHUNK_SIZE = 1048576;

    /** @var Closure(string): mixed */
    private Closure $openStream;

    public function __construct(?callable $openStream = null)
    {
        $this->openStream = $openStream === null
            ? static fn (string $path) => @fopen($path, 'rb')
            : Closure::fromCallable($openStream);
    }

    public function preview(string $absolutePath, int $expectedSize, string $contentType, ?string $rangeHeader): Response
    {
        $normalizedType = $this->normalizedContentType($contentType);
        $stream = null;

        try {
            if ($expectedSize < 1 || !$this->isAbsolutePath($absolutePath) || !is_readable($absolutePath)) {
                throw $this->genericFileException();
            }

            $stream = ($this->openStream)($absolutePath);
            if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
                throw $this->genericFileException();
            }

            $stat = @fstat($stream);
            if (!is_array($stat)
                || !is_int($stat['mode'] ?? null)
                || ($stat['mode'] & 0170000) !== 0100000
                || !is_int($stat['size'] ?? null)
                || $stat['size'] !== $expectedSize
            ) {
                throw $this->genericFileException();
            }
            if (fseek($stream, 0, SEEK_SET) !== 0) {
                throw $this->genericFileException();
            }

            try {
                $range = SingleByteRange::fromHeader($rangeHeader, $expectedSize);
            } catch (UnsatisfiableByteRange) {
                fclose($stream);
                $stream = null;

                return $this->withCommonHeaders(new Response('', 416), $normalizedType, 0)
                    ->withHeader('Content-Range', 'bytes */' . $expectedSize);
            }

            $start = $range?->start() ?? 0;
            $length = $range?->length() ?? $expectedSize;
            if (fseek($stream, $start, SEEK_SET) !== 0) {
                throw $this->genericFileException();
            }

            $response = Response::stream(static function () use ($stream, $length): void {
                $remaining = $length;
                try {
                    while ($remaining > 0) {
                        $chunk = fread($stream, min(self::CHUNK_SIZE, $remaining));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        echo $chunk;
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($stream);
                }
            }, $range === null ? 200 : 206);
            $response = $this->withCommonHeaders($response, $normalizedType, $length);
            if ($range !== null) {
                $response = $response->withHeader('Content-Range', $range->contentRange());
            }
            $stream = null;

            return $response;
        } catch (Throwable) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $this->genericFileException();
        }
    }

    private function normalizedContentType(string $contentType): string
    {
        return match ($contentType) {
            'video/mp4', 'application/mp4' => 'video/mp4',
            'video/quicktime' => 'video/quicktime',
            'video/webm' => 'video/webm',
            default => throw $this->genericFileException(),
        };
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{1,2})/', $path) === 1;
    }

    private function withCommonHeaders(Response $response, string $contentType, int $contentLength): Response
    {
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) $contentLength)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'inline; filename="source-preview"');
    }

    private function genericFileException(): RuntimeException
    {
        return new RuntimeException('Unable to prepare private preview response.');
    }
}
