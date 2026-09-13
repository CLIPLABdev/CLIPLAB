<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PlanRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id: int|string, credits: int|string}|null */
    public function findActiveBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, credits FROM plans WHERE slug = :slug AND is_active = 1 LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $plan = $statement->fetch(PDO::FETCH_ASSOC);

        return $plan === false ? null : $plan;
    }
}
