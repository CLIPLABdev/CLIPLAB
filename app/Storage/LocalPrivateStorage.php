<?php

declare(strict_types=1);

namespace App\Storage;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Media\StoredObject;

final class LocalPrivateStorage implements PrivateStorage, PrivateStagingArea
{
    /** @var \Closure(string): bool */
    private \Closure $uploadedFileVerifier;
    private string $root;
    /** @var array<int, array{path: string, object_key: string}> */
    private array $stagingFiles = [];

    public function __construct(string $root, private int $uploadMaxBytes, ?callable $uploadedFileVerifier = null)
    {
        if ($uploadMaxBytes < 1) {
            throw new \InvalidArgumentException('The upload limit must be positive.');
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException('Private media storage is unavailable.');
        }
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new \RuntimeException('Private media storage is unavailable.');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->uploadedFileVerifier = $uploadedFileVerifier === null
            ? static fn (string $path): bool => is_uploaded_file($path)
            : \Closure::fromCallable($uploadedFileVerifier);
    }

    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
    {
        if (!($this->uploadedFileVerifier)($temporaryPath)) {
            throw MediaValidationException::withCode('invalid_upload');
        }
        $stream = @fopen($temporaryPath, 'rb');
        if ($stream === false) {
            throw MediaValidationException::withCode('invalid_upload');
        }
        try {
            return $this->putStream($stream, $objectKey, $this->uploadMaxBytes);
        } finally {
            fclose($stream);
        }
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || $maxBytes < 1) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $staging = $this->createStaging($objectKey);
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw MediaValidationException::withCode('storage_write_failed');
                }
                if ($chunk === '') {
                    continue;
                }
                $staging->write($chunk, $maxBytes);
            }
            return $this->promoteStaging($staging, $objectKey, $maxBytes);
        } catch (\Throwable $exception) {
            $this->discardStaging($staging);
            if ($exception instanceof MediaValidationException) {
                throw $exception;
            }
            throw MediaValidationException::withCode('storage_write_failed');
        }
    }

    public function createStaging(string $objectKey): PrivateStagingFile
    {
        $finalPath = $this->absolutePath($objectKey);
        $directory = $this->safeDirectory($finalPath);
        $path = $directory . DIRECTORY_SEPARATOR . basename($finalPath) . '.' . bin2hex(random_bytes(16)) . '.download.part';
        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $staging = new PrivateStagingFile($path, $stream);
        $this->stagingFiles[spl_object_id($staging)] = ['path' => $path, 'object_key' => $objectKey];
        return $staging;
    }

    public function promoteStaging(PrivateStagingFile $staging, string $objectKey, int $maxBytes): StoredObject
    {
        if ($maxBytes < 1) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $entry = $this->stagingEntry($staging, $objectKey);
        $staging->finish();
        $size = @filesize($entry['path']);
        if ($size === false || $size < 1 || $size !== $staging->sizeBytes()) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        if ($size > $maxBytes) {
            throw MediaValidationException::withCode('media_too_large');
        }
        $hash = @hash_file('sha256', $entry['path']);
        if (!is_string($hash)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $finalPath = $this->absolutePath($objectKey);
        if ($finalPath !== $this->safeDirectory($finalPath) . DIRECTORY_SEPARATOR . basename($finalPath) || file_exists($finalPath) || !@rename($entry['path'], $finalPath)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        unset($this->stagingFiles[spl_object_id($staging)]);
        return new StoredObject($objectKey, $size, $hash);
    }

    public function discardStaging(PrivateStagingFile $staging): void
    {
        $id = spl_object_id($staging);
        $entry = $this->stagingFiles[$id] ?? null;
        $staging->close();
        unset($this->stagingFiles[$id]);
        if ($entry !== null && is_file($entry['path'])) {
            @unlink($entry['path']);
        }
    }

    public function absolutePath(string $objectKey): string
    {
        if ($objectKey === '' || str_contains($objectKey, "\0") || preg_match('/^(?:[A-Za-z]:)?[\\\\\/]/', $objectKey) === 1) {
            throw MediaValidationException::withCode('unsafe_object_key');
        }
        $segments = preg_split('#[\\\\/]#', $objectKey) ?: [];
        if ($segments === []) {
            throw MediaValidationException::withCode('unsafe_object_key');
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $segment) !== 1) {
                throw MediaValidationException::withCode('unsafe_object_key');
            }
        }
        return $this->root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    public function delete(string $objectKey): void
    {
        $path = $this->absolutePath($objectKey);
        $directory = realpath(dirname($path));
        if ($directory === false || !$this->isWithinRoot($directory)) {
            throw MediaValidationException::withCode('unsafe_object_key');
        }
        if (is_file($path) && !@unlink($path)) {
            throw MediaValidationException::withCode('storage_delete_failed');
        }
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            throw MediaValidationException::withCode('storage_delete_failed');
        }
        $pattern = '/\A' . preg_quote(basename($path), '/') . '\.[a-f0-9]{32}\.download\.part\z/D';
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) !== 1) {
                continue;
            }
            $stagingPath = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($stagingPath) || !is_file($stagingPath)) {
                continue;
            }
            if (!@unlink($stagingPath) && is_file($stagingPath)) {
                throw MediaValidationException::withCode('storage_delete_failed');
            }
        }
    }

    private function isWithinRoot(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }

    private function safeDirectory(string $path): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !$this->isWithinRoot($resolvedDirectory)) {
            throw MediaValidationException::withCode('unsafe_object_key');
        }
        return $resolvedDirectory;
    }

    /** @return array{path: string, object_key: string} */
    private function stagingEntry(PrivateStagingFile $staging, string $objectKey): array
    {
        $entry = $this->stagingFiles[spl_object_id($staging)] ?? null;
        if ($entry === null || $entry['object_key'] !== $objectKey || $entry['path'] !== $staging->path()) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        return $entry;
    }
}
