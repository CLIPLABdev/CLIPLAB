<?php

declare(strict_types=1);

namespace App\Media;

use App\Exceptions\MediaValidationException;

final class UploadValidator
{
    /** @var array<string, list<string>> */
    private const ALLOWED_MIME_TYPES = [
        'mp4' => ['video/mp4', 'application/mp4'],
        'mov' => ['video/quicktime', 'video/mp4'],
        'webm' => ['video/webm'],
    ];

    /** @var \Closure(string): (string|false) */
    private \Closure $mimeDetector;

    public function __construct(private int $maxBytes, ?callable $mimeDetector = null)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The upload limit must be positive.');
        }
        $this->mimeDetector = $mimeDetector === null
            ? static fn (string $path): string|false => (new \finfo(FILEINFO_MIME_TYPE))->file($path)
            : \Closure::fromCallable($mimeDetector);
    }

    /** @param array<string, mixed> $file */
    public function validate(array $file): ValidatedUpload
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) && !ctype_digit((string) $error)) {
            throw MediaValidationException::withCode('invalid_upload');
        }

        $error = (int) $error;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw MediaValidationException::withCode('upload_too_large');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw MediaValidationException::withCode('invalid_upload');
        }

        return $this->validatePath(
            is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
            is_string($file['name'] ?? null) ? $file['name'] : '',
            is_int($file['size'] ?? null) || ctype_digit((string) ($file['size'] ?? '')) ? (int) $file['size'] : null
        );
    }

    public function validatePath(string $path, string $originalName, ?int $declaredSize = null): ValidatedUpload
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME_TYPES[$extension])) {
            throw MediaValidationException::withCode('unsupported_extension');
        }
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw MediaValidationException::withCode('invalid_upload');
        }

        $size = filesize($path);
        if ($size === false || $size < 1 || $size > $this->maxBytes || ($declaredSize !== null && $declaredSize !== $size)) {
            throw MediaValidationException::withCode($size !== false && $size > $this->maxBytes ? 'upload_too_large' : 'invalid_upload');
        }

        $mime = ($this->mimeDetector)($path);
        if (!is_string($mime) || !in_array(strtolower($mime), self::ALLOWED_MIME_TYPES[$extension], true) || !$this->signatureMatches($path, $extension)) {
            throw MediaValidationException::withCode('invalid_media_container');
        }

        return new ValidatedUpload($path, basename($originalName), $extension, strtolower($mime), $size);
    }

    public function mimeAllowedForExtension(string $mimeType, string $extension): bool
    {
        return isset(self::ALLOWED_MIME_TYPES[$extension])
            && in_array(strtolower(trim(explode(';', $mimeType, 2)[0])), self::ALLOWED_MIME_TYPES[$extension], true);
    }

    private function signatureMatches(string $path, string $extension): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $head = fread($handle, 32);
        } finally {
            fclose($handle);
        }
        if (!is_string($head)) {
            return false;
        }
        if ($extension === 'webm') {
            return str_starts_with($head, "\x1A\x45\xDF\xA3");
        }
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }
        $brand = substr($head, 8, 4);
        if ($extension === 'mov') {
            return $brand === 'qt  ';
        }

        return $brand !== 'qt  ' && preg_match('/^[\x20-\x7e]{4}$/', $brand) === 1;
    }
}
