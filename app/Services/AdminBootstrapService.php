<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SystemLogRepository;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminBootstrapService
{
    public function __construct(private PDO $pdo, private SystemLogRepository $logs)
    {
    }

    public function provision(string $email, string $name, ?string $password, bool $promote): int
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Administrator email is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->findByEmail($email);
            if (is_array($existing)) {
                if (!$promote) {
                    throw new DomainException('The account already exists. Use --promote explicitly.');
                }
                $statement = $this->pdo->prepare("UPDATE users SET role = 'admin', status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $statement->execute(['id' => $existing['id']]);
                $userId = (int) $existing['id'];
                $this->logs->record('warning', 'admin.account_promoted', ['source' => 'cli'], null, 'user', $userId);
                $this->pdo->commit();

                return $userId;
            }
            if ($promote) {
                throw new DomainException('The account to promote was not found.');
            }
            $name = trim($name);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                throw new InvalidArgumentException('Administrator name is invalid.');
            }
            if (!is_string($password) || strlen($password) < 12 || strlen($password) > 4096 || str_contains($password, "\0")) {
                throw new DomainException('A password with at least 12 characters is required.');
            }
            $plan = $this->activeFreePlan();
            if ($plan === null) {
                throw new RuntimeException('The active Free plan is required.');
            }
            $credits = (int) $plan['credits'];
            $insert = $this->pdo->prepare(
                "INSERT INTO users (name, email, password_hash, plan_id, credits, role, status)
                 VALUES (:name, :email, :password_hash, :plan_id, :credits, 'admin', 'active')"
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'plan_id' => (int) $plan['id'],
                'credits' => $credits,
            ]);
            $userId = (int) $this->pdo->lastInsertId();
            if ($credits > 0) {
                $ledger = $this->pdo->prepare(
                    "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, reference_id, description)
                     VALUES (:user_id, 'credit', :amount, :balance, 'registration', NULL, 'Créditos iniciais')"
                );
                $ledger->execute(['user_id' => $userId, 'amount' => $credits, 'balance' => $credits]);
            }
            $this->logs->record('warning', 'admin.account_created', ['source' => 'cli'], null, 'user', $userId);
            $this->pdo->commit();

            return $userId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    private function findByEmail(string $email): ?array
    {
        $sql = 'SELECT id, role, status FROM users WHERE email = :email LIMIT 1';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function activeFreePlan(): ?array
    {
        $sql = "SELECT id, credits FROM plans WHERE slug = 'free' AND is_active = 1 LIMIT 1";
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
