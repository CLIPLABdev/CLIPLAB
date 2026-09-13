<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Credits\CreditReservation;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class CreditReservationRepository
{
    private const MAX_UNITS = 2147483647;
    private const MAX_UNSIGNED_INT = 4294967295;

    public function __construct(private PDO $pdo)
    {
    }

    public function lockUserCredits(int $userId, bool $activeOnly): ?int
    {
        $this->assertPositiveId($userId);
        $sql = 'SELECT credits FROM users WHERE id = :user_id';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' LIMIT 1' . $this->forUpdate();
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['user_id' => $userId]);
        $credits = $statement->fetchColumn();

        return $credits === false ? null : (int) $credits;
    }

    public function projectBelongsToUser(int $projectId, int $userId): bool
    {
        $this->assertPositiveId($projectId);
        $this->assertPositiveId($userId);
        $statement = $this->pdo->prepare(
            'SELECT id FROM projects WHERE id = :project_id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function findLockedByUserAndKey(int $userId, string $idempotencyHash): ?CreditReservation
    {
        $this->assertPositiveId($userId);
        $this->assertHash($idempotencyHash);
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, project_id, operation, units, status
             FROM credit_reservations
             WHERE user_id = :user_id AND idempotency_key = :idempotency_key
             LIMIT 1' . $this->forUpdate()
        );
        $statement->execute(['user_id' => $userId, 'idempotency_key' => $idempotencyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function userIdForReservation(int $reservationId): ?int
    {
        $this->assertPositiveId($reservationId);
        $statement = $this->pdo->prepare('SELECT user_id FROM credit_reservations WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $reservationId]);
        $userId = $statement->fetchColumn();

        return $userId === false ? null : (int) $userId;
    }

    public function findById(int $reservationId): ?CreditReservation
    {
        $this->assertPositiveId($reservationId);
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, project_id, operation, units, status
             FROM credit_reservations WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $reservationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findForAnalysis(
        int $reservationId,
        int $userId,
        int $projectId,
        int $units,
        string $promptVersion
    ): ?CreditReservation {
        $this->assertPositiveId($reservationId);
        $this->assertPositiveId($userId);
        $this->assertPositiveId($projectId);
        $this->assertUnits($units);
        if (trim($promptVersion) === '') {
            throw new InvalidArgumentException('Analysis prompt version is invalid.');
        }
        $logicalKey = 'ai:analyze:' . $projectId . ':' . $promptVersion;
        $statement = $this->pdo->prepare(
            "SELECT id, user_id, project_id, operation, units, status
             FROM credit_reservations
             WHERE id = :id AND user_id = :user_id AND project_id = :project_id
               AND operation = 'ai_analysis' AND units = :units AND idempotency_key = :idempotency_key
             LIMIT 1"
        );
        $statement->execute([
            'id' => $reservationId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'units' => $units,
            'idempotency_key' => hash('sha256', $userId . ':' . $logicalKey),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findLockedById(int $reservationId): ?CreditReservation
    {
        $this->assertPositiveId($reservationId);
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, project_id, operation, units, status
             FROM credit_reservations
             WHERE id = :id
             LIMIT 1' . $this->forUpdate()
        );
        $statement->execute(['id' => $reservationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(int $userId, int $projectId, int $units, string $idempotencyHash): CreditReservation
    {
        $this->assertPositiveId($userId);
        $this->assertPositiveId($projectId);
        $this->assertUnits($units);
        $this->assertHash($idempotencyHash);
        $statement = $this->pdo->prepare(
            "INSERT INTO credit_reservations
                (user_id, project_id, operation, units, status, idempotency_key)
             VALUES
                (:user_id, :project_id, 'ai_analysis', :units, 'reserved', :idempotency_key)"
        );
        $statement->execute([
            'user_id' => $userId,
            'project_id' => $projectId,
            'units' => $units,
            'idempotency_key' => $idempotencyHash,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Credit reservation could not be created.');
        }

        return new CreditReservation($id, $userId, $projectId, $units, 'reserved');
    }

    public function attachDebit(int $reservationId, int $transactionId): void
    {
        $this->assertPositiveId($reservationId);
        $this->assertPositiveId($transactionId);
        $statement = $this->pdo->prepare(
            "UPDATE credit_reservations
             SET credit_transaction_id = :transaction_id
             WHERE id = :id AND status = 'reserved' AND credit_transaction_id IS NULL"
        );
        $statement->execute(['id' => $reservationId, 'transaction_id' => $transactionId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Credit reservation debit could not be attached.');
        }
    }

    public function markConsumed(int $reservationId): void
    {
        $this->assertPositiveId($reservationId);
        $statement = $this->pdo->prepare(
            "UPDATE credit_reservations
             SET status = 'consumed', consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'reserved'"
        );
        $statement->execute(['id' => $reservationId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Credit reservation could not be consumed.');
        }
    }

    public function markRefunded(int $reservationId, int $transactionId): void
    {
        $this->assertPositiveId($reservationId);
        $this->assertPositiveId($transactionId);
        $statement = $this->pdo->prepare(
            "UPDATE credit_reservations
             SET status = 'refunded', refund_transaction_id = :transaction_id,
                 refunded_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'reserved' AND refund_transaction_id IS NULL"
        );
        $statement->execute(['id' => $reservationId, 'transaction_id' => $transactionId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Credit reservation could not be refunded.');
        }
    }

    public function updateUserBalance(int $userId, int $balance): void
    {
        $this->assertPositiveId($userId);
        if ($balance < 0 || $balance > self::MAX_UNSIGNED_INT) {
            throw new InvalidArgumentException('Credit balance is invalid.');
        }
        $statement = $this->pdo->prepare('UPDATE users SET credits = :credits WHERE id = :user_id');
        $statement->execute(['credits' => $balance, 'user_id' => $userId]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CreditReservation
    {
        if (($row['operation'] ?? null) !== 'ai_analysis') {
            throw new RuntimeException('Credit reservation operation is invalid.');
        }

        return new CreditReservation(
            (int) ($row['id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            (int) ($row['project_id'] ?? 0),
            (int) ($row['units'] ?? 0),
            (string) ($row['status'] ?? '')
        );
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Credit reservation identifier is invalid.');
        }
    }

    private function assertUnits(int $units): void
    {
        if ($units < 1 || $units > self::MAX_UNITS) {
            throw new InvalidArgumentException('Credit reservation units are invalid.');
        }
    }

    private function assertHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('Credit reservation idempotency hash is invalid.');
        }
    }
}
