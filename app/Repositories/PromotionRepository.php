<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PromotionRepository
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed> $values */
    public function create(array $values): int
    {
        $values['delivery_kind'] ??= 'banner';
        $statement = $this->pdo->prepare('INSERT INTO promotions (title, body, cta_label, cta_url, image_url, delivery_kind, placement, audience, plan_id, user_id, starts_at, ends_at, is_active) VALUES (:title,:body,:cta_label,:cta_url,:image_url,:delivery_kind,:placement,:audience,:plan_id,:user_id,:starts_at,:ends_at,:is_active)');
        $statement->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function all(): array { return $this->pdo->query('SELECT * FROM promotions ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC); }

    public function delete(int $id): void { $this->pdo->prepare('DELETE FROM promotions WHERE id=:id')->execute(['id'=>$id]); }
    /** @param array<string,mixed> $values */ public function update(int $id,array $values): void { $values['id']=$id; $this->pdo->prepare('UPDATE promotions SET title=:title,body=:body,cta_label=:cta_label,cta_url=:cta_url,image_url=:image_url,delivery_kind=:delivery_kind,placement=:placement,audience=:audience,plan_id=:plan_id,user_id=:user_id,starts_at=:starts_at,ends_at=:ends_at,is_active=:is_active,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute($values); }

    /** @return list<array<string,mixed>> */
    public function activeAt(\DateTimeImmutable $now): array
    {
        $query = $this->pdo->prepare("SELECT * FROM promotions WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= :starts_now) AND (ends_at IS NULL OR ends_at > :ends_now) ORDER BY id DESC");
        $query->execute(['starts_now' => $now->format('Y-m-d H:i:s'), 'ends_now' => $now->format('Y-m-d H:i:s')]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
