<?php

declare(strict_types=1);

namespace App\Plans;

use InvalidArgumentException;

final class PlanLimits
{
    public const DEFAULT_MAX_UPLOAD_BYTES = 104857600;
    public const DEFAULT_STORAGE_BYTES = 1073741824;

    private const FEATURE_KEYS = ['exports_hd', 'priority_processing', 'team_access', 'limits'];
    private const LIMIT_KEYS = ['max_upload_bytes', 'storage_bytes'];

    private function __construct(
        private bool $exportsHd,
        private bool $priorityProcessing,
        private bool $teamAccess,
        private int $maxUploadBytes,
        private int $storageBytes
    ) {
    }

    /** @param array<string, mixed> $features */
    public static function fromFeatures(array $features): self
    {
        self::assertOnlyKeys($features, self::FEATURE_KEYS);

        foreach (['exports_hd', 'priority_processing', 'team_access'] as $key) {
            if (array_key_exists($key, $features) && !is_bool($features[$key])) {
                throw new InvalidArgumentException('Plan feature flags must be boolean.');
            }
        }

        $limits = array_key_exists('limits', $features) ? $features['limits'] : [];
        if (!is_array($limits)) {
            throw new InvalidArgumentException('Plan limits must be an object.');
        }
        self::assertOnlyKeys($limits, self::LIMIT_KEYS);

        $maxUploadBytes = array_key_exists('max_upload_bytes', $limits)
            ? $limits['max_upload_bytes']
            : self::DEFAULT_MAX_UPLOAD_BYTES;
        $storageBytes = array_key_exists('storage_bytes', $limits)
            ? $limits['storage_bytes']
            : self::DEFAULT_STORAGE_BYTES;
        foreach ([$maxUploadBytes, $storageBytes] as $bytes) {
            if (!is_int($bytes) || $bytes < 1) {
                throw new InvalidArgumentException('Plan byte limits must be positive integers.');
            }
        }

        return new self(
            $features['exports_hd'] ?? false,
            $features['priority_processing'] ?? false,
            $features['team_access'] ?? false,
            $maxUploadBytes,
            $storageBytes
        );
    }

    /** @return array{exports_hd:bool,priority_processing:bool,team_access:bool,limits:array{max_upload_bytes:int,storage_bytes:int}} */
    public function toArray(): array
    {
        return [
            'exports_hd' => $this->exportsHd,
            'priority_processing' => $this->priorityProcessing,
            'team_access' => $this->teamAccess,
            'limits' => [
                'max_upload_bytes' => $this->maxUploadBytes,
                'storage_bytes' => $this->storageBytes,
            ],
        ];
    }

    public function maxUploadBytes(): int
    {
        return $this->maxUploadBytes;
    }

    public function storageBytes(): int
    {
        return $this->storageBytes;
    }

    /** @param array<string, mixed> $values @param list<string> $allowed */
    private static function assertOnlyKeys(array $values, array $allowed): void
    {
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Plan features contain an unknown key.');
            }
        }
    }
}
