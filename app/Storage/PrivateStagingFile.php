<?php

declare(strict_types=1);

namespace App\Storage;

use App\Exceptions\MediaValidationException;

final class PrivateStagingFile
{
    /** @var resource|null */
    private $stream;
    private int $sizeBytes = 0;

    /** @param resource $stream */
    public function __construct(private string $path, $stream)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('A writable staging stream is required.');
        }
        $this->stream = $stream;
    }

    public function path(): string { return $this->path; }
    public function sizeBytes(): int { return $this->sizeBytes; }

    public function write(string $chunk, int $maxBytes): void
    {
        if (!is_resource($this->stream)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $this->sizeBytes += strlen($chunk);
        if ($this->sizeBytes > $maxBytes) {
            throw MediaValidationException::withCode('media_too_large');
        }
        if ($chunk !== '' && fwrite($this->stream, $chunk) !== strlen($chunk)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
    }

    public function finish(): void
    {
        if (!is_resource($this->stream)) {
            return;
        }
        if (!fflush($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
            throw MediaValidationException::withCode('storage_write_failed');
        }
        fclose($this->stream);
        $this->stream = null;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }
}
