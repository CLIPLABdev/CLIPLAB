<?php

declare(strict_types=1);

namespace App\Billing;

use PDO;

/** Read-only financial reporting. Credit ledger data is deliberately out of scope. */
final class FinancialRepository
{
    public function __construct(private PDO $pdo) {}

    /** Production BRL only; never treats sandbox or credits as revenue. */
    public function dashboard(?int $userId=null,?\DateTimeImmutable $now=null):array
    {
        $now=($now??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $where=" WHERE environment='production' AND currency='BRL'".($userId!==null?' AND user_id=:owner':'');
        $owner=$userId===null?[]:['owner'=>$userId];
        $payments=$this->pdo->prepare("SELECT COALESCE(SUM(paid_amount_cents-refunded_amount_cents),0) AS net_total_cents, COALESCE(SUM(CASE WHEN paid_at>=:month_start AND paid_at<:month_end THEN paid_amount_cents-refunded_amount_cents ELSE 0 END),0) AS net_month_cents, COALESCE(SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END),0) AS payments_paid, COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) AS payments_pending, COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) AS payments_failed, COALESCE(SUM(refunded_amount_cents),0) AS refunded_cents FROM billing_payments".$where);
        $payments->execute($owner+['month_start'=>$now->format('Y-m-01 00:00:00'),'month_end'=>$now->modify('first day of next month')->format('Y-m-01 00:00:00')]);
        $subscriptions=$this->pdo->prepare("SELECT COUNT(*) AS subscriptions_total, COALESCE(SUM(CASE WHEN status IN ('active','trialing') THEN 1 ELSE 0 END),0) AS subscriptions_active, COALESCE(SUM(CASE WHEN status='canceled' THEN 1 ELSE 0 END),0) AS subscriptions_canceled FROM billing_subscriptions".$where);
        $subscriptions->execute($owner);
        return array_map('intval',($payments->fetch(PDO::FETCH_ASSOC)?:[])+($subscriptions->fetch(PDO::FETCH_ASSOC)?:[]));
    }

    /** @param array<string,mixed> $input @return array{records:list<array<string,mixed>>,totals:array<string,mixed>} */
    public function report(array $input): array
    {
        [$where, $parameters, $filters] = $this->filters($input);
        $base = ' FROM billing_payments b LEFT JOIN users u ON u.id=b.user_id LEFT JOIN plans p ON p.id=b.plan_id' . $where;
        $totals = $this->pdo->prepare('SELECT COUNT(*) AS count, COALESCE(SUM(b.gross_amount_cents),0) AS gross_cents, COALESCE(SUM(b.paid_amount_cents-b.refunded_amount_cents),0) AS paid_cents' . $base);
        $totals->execute($parameters);
        $statement = $this->pdo->prepare('SELECT b.id,b.user_id,b.plan_id,b.provider,b.environment,b.status,b.currency,b.gross_amount_cents,b.discount_cents,b.paid_amount_cents,b.refunded_amount_cents,(b.paid_amount_cents-b.refunded_amount_cents) AS net_amount_cents,b.paid_at,u.email AS user_email,p.name AS plan_name' . $base . ' ORDER BY b.id DESC LIMIT 50');
        $statement->execute($parameters);

        return ['records' => $statement->fetchAll(PDO::FETCH_ASSOC), 'totals' => $totals->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'gross_cents' => 0, 'paid_cents' => 0], 'filters' => $filters];
    }

    /** @param array<string,mixed> $input @return array{0:string,1:array<string,mixed>,2:array<string,string>} */
    private function filters(array $input): array
    {
        $environment=in_array($input['environment']??null,['production','sandbox'],true)?$input['environment']:'production';
        $currency=is_string($input['currency']??null)&&preg_match('/^[A-Z]{3}$/D',$input['currency'])===1?$input['currency']:'BRL';
        $clauses = ['b.environment=:environment','b.currency=:currency'];
        $parameters = ['environment'=>$environment,'currency'=>$currency];
        $filters = ['environment'=>$environment,'currency'=>$currency,'gateway' => '', 'status' => '', 'user' => '', 'plan' => '', 'from' => '', 'to' => ''];
        $gateway = is_string($input['gateway'] ?? null) ? $input['gateway'] : '';
        if (in_array($gateway, ['stripe', 'pagarme'], true)) { $clauses[] = 'b.provider=:gateway'; $parameters['gateway'] = $gateway; $filters['gateway'] = $gateway; }
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        if (in_array($status, ['paid', 'pending', 'failed', 'canceled', 'refunded', 'unpaid'], true)) { $clauses[] = 'b.status=:status'; $parameters['status'] = $status; $filters['status'] = $status; }
        foreach (['user' => 'user_id', 'plan' => 'plan_id'] as $key => $column) {
            $value = $input[$key] ?? '';
            if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1) { $clauses[] = 'b.' . $column . '=:' . $key; $parameters[$key] = (int) $value; $filters[$key] = $value; }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = $input[$key] ?? '';
            if (is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value) {
                $clauses[] = $key === 'from' ? 'b.paid_at>=:from' : 'b.paid_at<:to';
                $parameters[$key] = $key === 'from' ? $value . ' 00:00:00' : (new \DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d 00:00:00');
                $filters[$key] = $value;
            }
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $parameters, $filters];
    }
}
