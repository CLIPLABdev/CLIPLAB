<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id: int|string, email: string, password_hash: string, status: string}|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, password_hash, status FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : $user;
    }

    public function create(string $name, string $email, string $passwordHash, int $planId, int $credits): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits, role, status) VALUES (:name, :email, :password_hash, :plan_id, :credits, :role, :status)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
            'plan_id' => $planId,
            'credits' => $credits,
            'role' => 'user',
            'status' => 'active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function recordInitialCredit(int $userId, int $credits): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, reference_id, description) VALUES (:user_id, :type, :amount, :balance_after, :reference_type, NULL, :description)'
        );
        $statement->execute([
            'user_id' => $userId,
            'type' => 'credit',
            'amount' => $credits,
            'balance_after' => $credits,
            'reference_type' => 'registration',
            'description' => 'Créditos iniciais do plano Free',
        ]);
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute(['id' => $userId, 'password_hash' => $passwordHash]);
    }

    /** @return array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int, status: string}|null */
    public function findDashboardProfile(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email,
                    COALESCE((SELECT ct.balance_after FROM credit_transactions ct WHERE ct.user_id = u.id ORDER BY ct.created_at DESC, ct.id DESC LIMIT 1), u.credits) AS credits,
                    u.status, p.name AS plan_name, p.monthly_minutes
             FROM users u
             INNER JOIN plans p ON p.id = u.plan_id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'credits' => (int) $user['credits'],
            'plan_name' => (string) $user['plan_name'],
            'monthly_minutes' => (int) $user['monthly_minutes'],
            'status' => (string) $user['status'],
        ];
    }

    public function isActiveById(int $userId): bool
    {
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id = :user_id AND status = 'active' LIMIT 1");
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function emailTakenByAnotherUser(string $email, int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :user_id LIMIT 1');
        $statement->execute(['email' => $email, 'user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function updateProfile(int $userId, string $name, string $email): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :user_id');
        $statement->execute(['name' => $name, 'email' => $email, 'user_id' => $userId]);
    }

    public function findProfileIdentity(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,name,email FROM users WHERE id=:id LIMIT 1');
        $statement->execute(['id'=>$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        return $user === false ? null : $user;
    }
}
