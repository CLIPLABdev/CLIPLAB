<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CreditTransactionRepository
{
    private const MAX_AMOUNT = 2147483647;
    private const MAX_UNSIGNED_INT = 4294967295;

    public function __construct(private PDO $pdo)
    {
    }

    public function latestBalanceForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT balance_after
             FROM credit_transactions
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $balance = $statement->fetchColumn();

        if ($balance !== false) {
            return (int) $balance;
        }

        $statement = $this->pdo->prepare('SELECT credits FROM users WHERE id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $balance = $statement->fetchColumn();

        return $balance === false ? 0 : (int) $balance;
    }

    public function latestLockedBalanceForUser(int $userId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT balance_after
             FROM credit_transactions
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1' . $this->forUpdate()
        );
        $statement->execute(['user_id' => $userId]);
        $balance = $statement->fetchColumn();

        return $balance === false ? null : (int) $balance;
    }

    public function recordReservationEntry(
        int $userId,
        string $type,
        int $amount,
        int $balanceAfter,
        int $reservationId,
        string $description
    ): int {
        if ($userId < 1 || $reservationId < 1
            || !in_array($type, ['debit', 'credit'], true)
            || $amount < 1 || $amount > self::MAX_AMOUNT
            || $balanceAfter < 0 || $balanceAfter > self::MAX_UNSIGNED_INT
            || trim($description) === '' || mb_strlen($description) > 255) {
            throw new \InvalidArgumentException('Credit transaction is invalid.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, type, amount, balance_after, reference_type, reference_id, description)
             VALUES
                (:user_id, :type, :amount, :balance_after, :reference_type, :reference_id, :description)'
        );
        $statement->execute([
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => 'credit_reservation',
            'reference_id' => $reservationId,
            'description' => $description,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new \RuntimeException('Credit transaction could not be created.');
        }

        return $id;
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
