<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Media\Editor\EditorOptions;
use App\Media\StoredObject;
use PDO;

final class EditorLibraryRepository
{
    public const CATEGORIES = ['viral', 'podcast', 'clean', 'impact', 'custom'];
    public function __construct(private PDO $pdo) {}

    public function listOwned(int $userId): array
    {
        $query = $this->pdo->prepare('SELECT * FROM user_editor_templates WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
        $query->execute([$userId]);
        return array_map(fn (array $row): array => $this->template($row), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM user_editor_templates WHERE id = ? AND user_id = ?');
        $query->execute([$id, $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->template($row);
    }

    public function snapshot(int $id, int $userId): ?array
    {
        $template = $this->findOwned($id, $userId);
        return $template === null ? null : ['options' => $template['options'], 'aspect_ratio' => $template['aspect_ratio']];
    }

    public function saveOwned(int $userId, ?int $id, string $name, string $category, EditorOptions $options, string $aspectRatio): int
    {
        $name = trim($name);
        if (!mb_check_encoding($name, 'UTF-8') || $name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $name)
            || !in_array($category, self::CATEGORIES, true)) throw new \InvalidArgumentException('Use um nome de até 80 caracteres e uma categoria válida.');
        $this->assertRatio($aspectRatio);
        return $this->withAccountLock($userId, function () use ($userId, $id, $name, $category, $options, $aspectRatio): int {
            $this->assertLogo($options, $userId);
            if ($id !== null && $this->findOwned($id, $userId) === null) throw new \OutOfBoundsException('Template não encontrado.');
            if ($id === null && count($this->listOwned($userId)) >= 50) throw new \DomainException('Sua biblioteca permite até 50 templates.');
            $values = [$name, $category, json_encode($options->toArray(), JSON_THROW_ON_ERROR), $aspectRatio];
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO user_editor_templates (name, category, options_json, aspect_ratio, user_id) VALUES (?, ?, ?, ?, ?)')->execute([...$values, $userId]);
                return (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('UPDATE user_editor_templates SET name = ?, category = ?, options_json = ?, aspect_ratio = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')->execute([...$values, $id, $userId]);
            return $id;
        });
    }

    public function removeOwned(int $id, int $userId): bool
    {
        return $this->withAccountLock($userId, function () use ($id, $userId): bool {
            $query = $this->pdo->prepare('DELETE FROM user_editor_templates WHERE id = ? AND user_id = ?');
            $query->execute([$id, $userId]);
            return $query->rowCount() === 1;
        });
    }

    public function kitForUser(int $userId): array
    {
        $query = $this->pdo->prepare('SELECT options_json, aspect_ratio, favorites_json FROM user_brand_kits WHERE user_id = ?');
        $query->execute([$userId]); $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return ['options' => EditorOptions::fromArray([])->toArray(), 'aspect_ratio' => '9:16', 'favorites' => []];
        $owned = array_column($this->listOwned($userId), 'id');
        return ['options' => EditorOptions::fromArray(json_decode($row['options_json'], true, 16, JSON_THROW_ON_ERROR))->toArray(),
            'aspect_ratio' => $row['aspect_ratio'], 'favorites' => array_values(array_intersect(json_decode($row['favorites_json'], true, 8, JSON_THROW_ON_ERROR), $owned))];
    }

    public function saveKit(int $userId, EditorOptions $options, string $aspectRatio, array $favorites = []): void
    {
        $this->assertRatio($aspectRatio);
        if (count($favorites) > 50) throw new \InvalidArgumentException('Selecione até 50 favoritos.');
        $this->withAccountLock($userId, function () use ($userId, $options, $aspectRatio, $favorites): void {
            $this->assertLogo($options, $userId);
            foreach ($favorites as $id) if (!is_int($id) || $this->findOwned($id, $userId) === null) throw new \OutOfBoundsException('Template favorito não encontrado.');
            $values = [json_encode($options->toArray(), JSON_THROW_ON_ERROR), $aspectRatio, json_encode(array_values(array_unique($favorites)), JSON_THROW_ON_ERROR), $userId];
            $exists = $this->pdo->prepare('SELECT user_id FROM user_brand_kits WHERE user_id = ?'); $exists->execute([$userId]);
            $sql = $exists->fetchColumn() === false
                ? 'INSERT INTO user_brand_kits (options_json, aspect_ratio, favorites_json, user_id) VALUES (?, ?, ?, ?)'
                : 'UPDATE user_brand_kits SET options_json = ?, aspect_ratio = ?, favorites_json = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?';
            $this->pdo->prepare($sql)->execute($values);
        });
    }

    public function listLogosOwned(int $userId): array
    {
        $query = $this->pdo->prepare('SELECT * FROM user_brand_logos WHERE user_id = ? ORDER BY id DESC'); $query->execute([$userId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findLogoOwned(int $assetId, int $userId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM user_brand_logos WHERE id = ? AND user_id = ?'); $query->execute([$assetId, $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $row;
    }

    public function resolveLogoForProject(int $assetId, int $projectId): ?string
    {
        $query = $this->pdo->prepare('SELECT l.object_key FROM user_brand_logos l INNER JOIN projects p ON p.user_id = l.user_id WHERE l.id = ? AND p.id = ?');
        $query->execute([$assetId, $projectId]); $key = $query->fetchColumn();
        return $key === false ? null : (string) $key;
    }

    public function brandLogoBytes(int $userId): int
    {
        $query = $this->pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM user_brand_logos WHERE user_id = ?'); $query->execute([$userId]);
        return (int) $query->fetchColumn();
    }

    public function addLogoOwned(int $userId, StoredObject $object, int $width, int $height): int
    {
        return $this->withAccountLock($userId, function () use ($userId, $object, $width, $height): int {
            $this->assertLogoCapacity($userId);
            if ($object->sizeBytes() < 1 || $object->sizeBytes() > 2097152 || min($width, $height) < 1 || max($width, $height) > 2048) throw new \InvalidArgumentException('Logo excede os limites.');
            $this->pdo->prepare('INSERT INTO user_brand_logos (user_id, object_key, size_bytes, sha256, width, height) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $object->objectKey(), $object->sizeBytes(), $object->sha256(), $width, $height]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    public function assertLogoCapacity(int $userId): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Logo admission requires the account transaction.');
        if (count($this->listLogosOwned($userId)) >= 5) throw new \DomainException('Sua marca permite até 5 logos imutáveis.');
    }

    /** Serializes admissions with other account quota operations on the same PDO. */
    public function withAccountLock(int $userId, callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $query = $this->pdo->prepare('SELECT id FROM users WHERE id = ?' . $lock); $query->execute([$userId]);
            if ($query->fetchColumn() === false) throw new \OutOfBoundsException('Conta não encontrada.');
            $result = $operation();
            if ($owns) $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function template(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'category' => $row['category'],
            'options' => EditorOptions::fromArray(json_decode($row['options_json'], true, 16, JSON_THROW_ON_ERROR))->toArray(),
            'aspect_ratio' => $row['aspect_ratio'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }
    private function assertRatio(string $ratio): void
    {
        if (!in_array($ratio, ['9:16', '1:1', '16:9'], true)) throw new \InvalidArgumentException('Escolha uma proporção válida.');
    }
    private function assertLogo(EditorOptions $options, int $userId): void
    {
        $id = $options->toArray()['logo_asset_id'] ?? 0;
        if ($id !== 0 && $this->findLogoOwned($id, $userId) === null) throw new \OutOfBoundsException('Logo não encontrado.');
    }
}
