<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use App\Repositories\UserConsentRepository;
use App\Services\MediaPipeConsentService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class MediaPipeConsentServiceTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $this->pdo = SafePhase5TestDatabase::using(
            getenv('TEST_DB_DSN'),
            static fn (string $dsn): PDO => new PDO(
                $dsn,
                getenv('TEST_DB_USERNAME') ?: null,
                getenv('TEST_DB_PASSWORD') ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            )
        );
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['consent-' . $suffix, 'Consent ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->userId = $this->createUser('owner-' . $suffix);
        $this->otherUserId = $this->createUser('other-' . $suffix);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->userId, $this->otherUserId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testGrantRevokeAndRegrantUseUtcClockAndRemainIdempotent(): void
    {
        $now = new DateTimeImmutable('2026-09-04 07:15:00', new DateTimeZone('America/Sao_Paulo'));
        $clockCalls = 0;
        $service = new MediaPipeConsentService(
            new UserConsentRepository($this->pdo),
            static function () use (&$now, &$clockCalls): DateTimeImmutable {
                ++$clockCalls;

                return $now;
            }
        );

        self::assertSame('mediapipe_metrics', MediaPipeConsentService::PURPOSE);
        self::assertSame('2026-09-04', MediaPipeConsentService::POLICY_VERSION);
        self::assertFalse($service->hasActive($this->userId));

        $service->grant($this->userId);
        $now = new DateTimeImmutable('2026-09-04 08:15:00', new DateTimeZone('America/Sao_Paulo'));
        $service->grant($this->userId);
        self::assertTrue($service->hasActive($this->userId));
        self::assertSame(1, $this->consentRowCount($this->userId, MediaPipeConsentService::POLICY_VERSION));
        self::assertSame(['2026-09-04 10:15:00', null], $this->consentDates($this->userId));

        $service->revoke($this->userId);
        $now = new DateTimeImmutable('2026-09-04 09:15:00', new DateTimeZone('America/Sao_Paulo'));
        $service->revoke($this->userId);
        self::assertFalse($service->hasActive($this->userId));
        self::assertSame(['2026-09-04 10:15:00', '2026-09-04 11:15:00'], $this->consentDates($this->userId));

        $service->grant($this->userId);
        self::assertTrue($service->hasActive($this->userId));
        self::assertSame(['2026-09-04 12:15:00', null], $this->consentDates($this->userId));
        self::assertSame(5, $clockCalls);
    }

    public function testActiveConsentIsIsolatedByUserPurposeAndPolicyVersion(): void
    {
        $repository = new UserConsentRepository($this->pdo);
        $at = new DateTimeImmutable('2026-09-04 10:00:00', new DateTimeZone('UTC'));
        $repository->grant($this->userId, MediaPipeConsentService::PURPOSE, '2026-09-03', $at);
        $repository->grant($this->otherUserId, MediaPipeConsentService::PURPOSE, MediaPipeConsentService::POLICY_VERSION, $at);
        $repository->grant($this->userId, 'another_metric', MediaPipeConsentService::POLICY_VERSION, $at);

        $service = new MediaPipeConsentService($repository, static fn (): DateTimeImmutable => $at);

        self::assertFalse($service->hasActive($this->userId));
        self::assertTrue($service->hasActive($this->otherUserId));
        $service->grant($this->userId);
        self::assertTrue($service->hasActive($this->userId));
        self::assertSame(1, $this->consentRowCount($this->userId, '2026-09-03'));
        self::assertSame(1, $this->consentRowCount($this->userId, MediaPipeConsentService::POLICY_VERSION));
    }

    public function testEveryPublicOperationRejectsNonPositiveUserIdentifiers(): void
    {
        $service = new MediaPipeConsentService(new UserConsentRepository($this->pdo));

        foreach (['hasActive', 'grant', 'revoke'] as $method) {
            foreach ([0, -1] as $userId) {
                try {
                    $service->{$method}($userId);
                    self::fail($method . ' must reject a non-positive user identifier.');
                } catch (InvalidArgumentException $exception) {
                    self::assertSame('Consent user identifier is invalid.', $exception->getMessage());
                }
            }
        }
    }

    private function createUser(string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Consent user', $prefix . '@example.test', 'not-a-real-hash', $this->planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function consentRowCount(int $userId, string $version): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_consents WHERE user_id = ? AND purpose = ? AND policy_version = ?'
        );
        $statement->execute([$userId, MediaPipeConsentService::PURPOSE, $version]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{string,string|null} */
    private function consentDates(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT granted_at, revoked_at FROM user_consents WHERE user_id = ? AND purpose = ? AND policy_version = ?'
        );
        $statement->execute([$userId, MediaPipeConsentService::PURPOSE, MediaPipeConsentService::POLICY_VERSION]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return [(string) $row['granted_at'], $row['revoked_at'] === null ? null : (string) $row['revoked_at']];
    }
}
