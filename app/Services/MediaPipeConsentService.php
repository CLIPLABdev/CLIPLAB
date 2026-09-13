<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserConsentRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class MediaPipeConsentService
{
    public const PURPOSE = 'mediapipe_metrics';
    public const POLICY_VERSION = '2026-09-04';

    /** @var callable(): DateTimeImmutable */
    private $clock;

    public function __construct(
        private UserConsentRepository $consents,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function hasActive(int $userId): bool
    {
        $this->assertUserId($userId);

        return $this->consents->isActive($userId, self::PURPOSE, self::POLICY_VERSION);
    }

    public function grant(int $userId): void
    {
        $this->assertUserId($userId);
        $this->consents->grant($userId, self::PURPOSE, self::POLICY_VERSION, $this->now());
    }

    public function revoke(int $userId): void
    {
        $this->assertUserId($userId);
        $this->consents->revoke($userId, self::PURPOSE, self::POLICY_VERSION, $this->now());
    }

    private function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Consent user identifier is invalid.');
        }
    }

    private function now(): DateTimeImmutable
    {
        $at = ($this->clock)();
        if (!$at instanceof DateTimeImmutable) {
            throw new RuntimeException('Consent clock must return DateTimeImmutable.');
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
