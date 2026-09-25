<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Créditos diários do plano.
 *
 * Uma vez por dia (no fuso da aplicação) cada conta ativa recebe os créditos
 * diários do seu plano. O saldo acumula até 30 dias de créditos diários; acima
 * disso a recarga não soma, mas créditos comprados ou dados pela administração
 * nunca são removidos.
 */
final class DailyPlanCreditService
{
    public const ACCUMULATION_DAYS = 30;
    private const MAX_BALANCE = 4294967295;

    public function __construct(private PDO $pdo, private ?DateTimeZone $timezone = null)
    {
    }

    /** @return array{granted:int,skipped:int,credits:int} */
    public function grantDue(?DateTimeImmutable $now = null, int $batchSize = 200): array
    {
        $timezone = $this->timezone ?? new DateTimeZone('America/Sao_Paulo');
        $today = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->format('Y-m-d');
        $summary = ['granted' => 0, 'skipped' => 0, 'credits' => 0];

        $candidates = $this->pdo->prepare(
            "SELECT u.id FROM users u INNER JOIN plans p ON p.id = u.plan_id
             WHERE u.status = 'active' AND p.daily_credits > 0
               AND (u.daily_credits_granted_on IS NULL OR u.daily_credits_granted_on < :today)
             ORDER BY u.id LIMIT " . max(1, min(1000, $batchSize))
        );
        $candidates->execute(['today' => $today]);

        foreach ($candidates->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            try {
                $amount = $this->grantUser((int) $userId, $today);
                $amount > 0 ? $summary['granted']++ : $summary['skipped']++;
                $summary['credits'] += $amount;
            } catch (Throwable) {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /** Retorna quantos créditos foram somados (0 quando já recebeu hoje ou o saldo já está no teto). */
    public function grantUser(int $userId, string $today): int
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                "SELECT u.credits, u.daily_credits_granted_on, p.daily_credits
                 FROM users u INNER JOIN plans p ON p.id = u.plan_id
                 WHERE u.id = :id AND u.status = 'active' LIMIT 1" . $this->forUpdate()
            );
            $lock->execute(['id' => $userId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['daily_credits'] < 1
                || ($row['daily_credits_granted_on'] !== null && (string) $row['daily_credits_granted_on'] >= $today)
            ) {
                $this->pdo->commit();
                return 0;
            }

            $daily = (int) $row['daily_credits'];
            $ledger = $this->pdo->prepare(
                'SELECT balance_after FROM credit_transactions WHERE user_id = :id ORDER BY created_at DESC, id DESC LIMIT 1' . $this->forUpdate()
            );
            $ledger->execute(['id' => $userId]);
            $latest = $ledger->fetchColumn();
            $balance = $latest === false ? (int) $row['credits'] : (int) $latest;
            $cap = min(self::MAX_BALANCE, $daily * self::ACCUMULATION_DAYS);
            $amount = max(0, min($daily, $cap - $balance));

            if ($amount > 0) {
                $this->pdo->prepare(
                    "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, reference_id, description)
                     VALUES (:user_id, 'credit', :amount, :balance, 'daily_plan_credit', NULL, 'Créditos diários do plano')"
                )->execute(['user_id' => $userId, 'amount' => $amount, 'balance' => $balance + $amount]);
                $this->pdo->prepare('UPDATE users SET credits = :credits WHERE id = :id')
                    ->execute(['credits' => $balance + $amount, 'id' => $userId]);
            }
            $this->pdo->prepare('UPDATE users SET daily_credits_granted_on = :today WHERE id = :id')
                ->execute(['today' => $today, 'id' => $userId]);
            $this->pdo->commit();

            return $amount;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
