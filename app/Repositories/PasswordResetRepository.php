<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class PasswordResetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Returns false if the identity changed before acquiring the account lock. */
    public function replaceForUser(int $userId, string $tokenHash, string $expiresAt, ?callable $enqueue = null, ?string $expectedEmail = null): bool
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();

        try {
            $lockedUser = $this->lockUser($userId, $expectedEmail !== null);
            if ($expectedEmail !== null && (
                $lockedUser === null
                || ($lockedUser['status'] ?? null) !== 'active'
                || !hash_equals(mb_strtolower(trim($expectedEmail)), mb_strtolower(trim((string) $lockedUser['email'])))
            )) {
                if ($ownsTransaction) $this->pdo->commit();
                return false;
            }
            $revoke = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND used_at IS NULL');
            $revoke->execute(['user_id' => $userId]);

            $insert = $this->pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
            $insert->execute([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);

            if ($enqueue !== null) $enqueue();
            if ($ownsTransaction) $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param callable(int): void $updatePassword */
    public function consumeValidToken(string $tokenHash, callable $updatePassword): bool
    {
        $this->pdo->beginTransaction();

        try {
            $lookup = $this->pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1');
            $lookup->execute(['token_hash' => $tokenHash]);
            $record = $lookup->fetch(PDO::FETCH_ASSOC);

            if ($record === false) {
                $this->pdo->rollBack();

                return false;
            }

            $userId = (int) $record['user_id'];
            $this->lockUser($userId);

            $statement = $this->pdo->prepare(
                'SELECT id, user_id FROM password_reset_tokens'
                . ' WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
                . ' LIMIT 1' . $this->lockClause()
            );
            $statement->execute(['token_hash' => $tokenHash]);
            $token = $statement->fetch(PDO::FETCH_ASSOC);

            if ($token === false) {
                $this->pdo->rollBack();

                return false;
            }

            $tokenId = (int) $token['id'];
            $updatePassword($userId);

            $consume = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id AND used_at IS NULL');
            $consume->execute(['id' => $tokenId]);

            $revoke = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND id != :id AND used_at IS NULL');
            $revoke->execute(['user_id' => $userId, 'id' => $tokenId]);

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** The FOR UPDATE read sees the latest committed identity, even inside an older caller transaction. */
    private function lockUser(int $userId, bool $withIdentity = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . ($withIdentity ? 'id, email, status' : 'id') . ' FROM users WHERE id = :id' . $this->lockClause());
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function lockClause(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
