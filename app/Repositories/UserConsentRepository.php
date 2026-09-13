<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

final class UserConsentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isActive(int $userId, string $purpose, string $policyVersion): bool
    {
        $this->assertIdentity($userId, $purpose, $policyVersion);
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM user_consents WHERE user_id = :user_id AND purpose = :purpose '
            . 'AND policy_version = :policy_version AND revoked_at IS NULL LIMIT 1'
        );
        $statement->execute($this->identity($userId, $purpose, $policyVersion));

        return $statement->fetchColumn() !== false;
    }

    public function grant(
        int $userId,
        string $purpose,
        string $policyVersion,
        DateTimeImmutable $at
    ): void {
        $this->assertIdentity($userId, $purpose, $policyVersion);
        $statement = $this->pdo->prepare(
            'INSERT INTO user_consents (user_id, purpose, policy_version, granted_at, revoked_at) '
            . 'VALUES (:user_id, :purpose, :policy_version, :at, NULL) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'granted_at = IF(revoked_at IS NULL, granted_at, VALUES(granted_at)), revoked_at = NULL'
        );
        $statement->execute($this->identity($userId, $purpose, $policyVersion) + ['at' => $this->date($at)]);
    }

    public function revoke(
        int $userId,
        string $purpose,
        string $policyVersion,
        DateTimeImmutable $at
    ): void {
        $this->assertIdentity($userId, $purpose, $policyVersion);
        $statement = $this->pdo->prepare(
            'UPDATE user_consents SET revoked_at = :at WHERE user_id = :user_id AND purpose = :purpose '
            . 'AND policy_version = :policy_version AND revoked_at IS NULL'
        );
        $statement->execute($this->identity($userId, $purpose, $policyVersion) + ['at' => $this->date($at)]);
    }

    private function assertIdentity(int $userId, string $purpose, string $policyVersion): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Consent user identifier is invalid.');
        }
        if (preg_match('/^[a-z0-9_:-]{1,64}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('Consent purpose is invalid.');
        }
        if (preg_match('/^[0-9-]{1,32}$/D', $policyVersion) !== 1) {
            throw new InvalidArgumentException('Consent policy version is invalid.');
        }
    }

    /** @return array{user_id:int,purpose:string,policy_version:string} */
    private function identity(int $userId, string $purpose, string $policyVersion): array
    {
        return ['user_id' => $userId, 'purpose' => $purpose, 'policy_version' => $policyVersion];
    }

    private function date(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
