<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Plans\PlanLimits;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class AccountRepository
{
    private const FILTERS = ['all', 'additions', 'consumption', 'refunds', 'adjustments'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function activePlans(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, slug, name, price_cents, monthly_minutes, credits, features, is_active
             FROM plans WHERE is_active = 1 ORDER BY price_cents ASC, id ASC'
        );
        $plans = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $features = json_decode((string) $row['features'], true);
            if (!is_array($features)) {
                throw new RuntimeException('Plan features are invalid.');
            }
            try {
                $normalized = PlanLimits::fromFeatures($features)->toArray();
            } catch (\Throwable $exception) {
                throw new RuntimeException('Plan features are invalid.', 0, $exception);
            }
            $plans[] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'price_cents' => (int) $row['price_cents'],
                'monthly_minutes' => (int) $row['monthly_minutes'],
                'credits' => (int) $row['credits'],
                'features' => $normalized,
                'is_active' => true,
            ];
        }

        $details = $this->planDetails();
        foreach ($plans as $index => $plan) {
            $plans[$index]['description'] = $details[$plan['id']]['description'] ?? '';
            $plans[$index]['daily_credits'] = $details[$plan['id']]['daily_credits'] ?? 0;
        }

        return $plans;
    }

    /**
     * Descrição e créditos diários ficam em colunas opcionais: bancos antigos
     * (ou fixtures de teste) sem essas colunas continuam funcionando.
     * @return array<int, array{description:string,daily_credits:int}>
     */
    private function planDetails(): array
    {
        try {
            $statement = $this->pdo->query('SELECT id, description, daily_credits FROM plans');
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
        $details = [];
        foreach ($rows as $row) {
            $details[(int) $row['id']] = [
                'description' => (string) ($row['description'] ?? ''),
                'daily_credits' => max(0, (int) ($row['daily_credits'] ?? 0)),
            ];
        }

        return $details;
    }

    /** @return array{items:list<array<string,mixed>>,filter:string,page:int,per_page:int,total:int,pages:int} */
    public function ledgerForUser(int $userId, string $filter, int $page, int $perPage = 25): array
    {
        if ($userId < 1 || $page < 1 || $perPage < 1 || $perPage > 25) {
            throw new InvalidArgumentException('Ledger pagination is invalid.');
        }
        if (!in_array($filter, self::FILTERS, true)) {
            throw new InvalidArgumentException('Ledger filter is invalid.');
        }

        [$where, $parameters] = $this->ledgerWhere($userId, $filter);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM credit_transactions WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $effectivePage = min($page, $pages);
        $offset = ($effectivePage - 1) * $perPage;

        $statement = $this->pdo->prepare(
            'SELECT id, type, amount, balance_after, reference_type, reference_id, description, created_at
             FROM credit_transactions WHERE ' . $where . '
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);
        $items = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'kind' => $this->ledgerKind((string) $row['type'], $row['reference_type']),
            'amount' => (int) $row['amount'],
            'balance_after' => (int) $row['balance_after'],
            'reference_type' => $row['reference_type'] === null ? null : (string) $row['reference_type'],
            'reference_id' => $row['reference_id'] === null ? null : (int) $row['reference_id'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));

        return [
            'items' => $items,
            'filter' => $filter,
            'page' => $effectivePage,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /** @return array{string,array{user_id:int}} */
    private function ledgerWhere(int $userId, string $filter): array
    {
        $where = 'user_id = :user_id';
        if ($filter === 'additions') {
            $where .= " AND type = 'credit' AND (reference_type IS NULL OR reference_type <> 'credit_reservation')";
        } elseif ($filter === 'consumption') {
            $where .= " AND type = 'debit'";
        } elseif ($filter === 'refunds') {
            $where .= " AND type = 'credit' AND reference_type = 'credit_reservation'";
        } elseif ($filter === 'adjustments') {
            $where .= " AND type = 'adjustment'";
        }

        return [$where, ['user_id' => $userId]];
    }

    private function ledgerKind(string $type, mixed $referenceType): string
    {
        if ($type === 'credit' && $referenceType === 'credit_reservation') {
            return 'refund';
        }
        if ($type === 'credit') {
            return 'addition';
        }
        if ($type === 'debit') {
            return 'consumption';
        }

        return 'adjustment';
    }
}
