<?php

declare(strict_types=1);

namespace App\Media;

final class UploadLimits
{
    private const MINIMUM_MULTIPART_HEADROOM = 16384;
    private const MAXIMUM_MULTIPART_HEADROOM = 1048576;

    private int $effectiveBytes;
    private ?int $phpCapacityBytes;

    private function __construct(
        private int $configuredBytes,
        private ?int $phpUploadMaxBytes,
        private ?int $phpPostMaxBytes
    ) {
        if ($configuredBytes < 1) {
            throw new \InvalidArgumentException('The configured upload limit must be positive.');
        }

        $postCapacity = self::postUploadCapacity($phpPostMaxBytes);
        $phpLimits = array_values(array_filter(
            [$phpUploadMaxBytes, $postCapacity],
            static fn (?int $bytes): bool => $bytes !== null
        ));
        $this->phpCapacityBytes = $phpLimits === [] ? null : min($phpLimits);
        $this->effectiveBytes = $this->phpCapacityBytes === null
            ? $configuredBytes
            : min($configuredBytes, $this->phpCapacityBytes);
    }

    public static function runtime(int $configuredBytes): self
    {
        return new self(
            $configuredBytes,
            self::parseIniBytes(ini_get('upload_max_filesize')),
            self::parseIniBytes(ini_get('post_max_size'))
        );
    }

    public static function parseIniBytes(string|false|null $value): ?int
    {
        if ($value === false || $value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        if (preg_match('/^([0-9]+)([A-Z])?$/i', $value, $matches) !== 1) {
            return null;
        }

        $bytes = (int) $matches[1];
        if ($bytes === 0) {
            return null;
        }

        $power = match (strtoupper($matches[2] ?? '')) {
            'K' => 1,
            'M' => 2,
            'G' => 3,
            default => 0,
        };

        for ($step = 0; $step < $power; $step++) {
            if ($bytes > intdiv(PHP_INT_MAX, 1024)) {
                return PHP_INT_MAX;
            }
            $bytes *= 1024;
        }

        return $bytes;
    }

    public function configuredBytes(): int
    {
        return $this->configuredBytes;
    }

    public function effectiveBytes(): int
    {
        return $this->effectiveBytes;
    }

    public function phpUploadMaxBytes(): ?int
    {
        return $this->phpUploadMaxBytes;
    }

    public function phpPostMaxBytes(): ?int
    {
        return $this->phpPostMaxBytes;
    }

    public function phpCapacityBytes(): ?int
    {
        return $this->phpCapacityBytes;
    }

    private static function postUploadCapacity(?int $postMaxBytes): ?int
    {
        if ($postMaxBytes === null) {
            return null;
        }

        $headroom = min(
            self::MAXIMUM_MULTIPART_HEADROOM,
            max(self::MINIMUM_MULTIPART_HEADROOM, intdiv($postMaxBytes, 100))
        );

        return max(1, $postMaxBytes - $headroom);
    }
}
