<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class RateLimiter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function hit(string $action, string $subject, int $max, int $windowSeconds): bool
    {
        if ($max < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit values must be positive.');
        }

        $key = hash('sha256', mb_strtolower(trim($subject)));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');
        $expiresAt = $now->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $this->deleteExpired($nowSql);
            $statement = $this->pdo->prepare(
                'SELECT id, attempts, expires_at FROM rate_limits WHERE rate_key = :rate_key AND action = :action' . $this->lockClause()
            );
            $statement->execute(['rate_key' => $key, 'action' => $action]);
            $record = $statement->fetch(PDO::FETCH_ASSOC);

            if ($record !== false && (string) $record['expires_at'] <= $nowSql) {
                $delete = $this->pdo->prepare('DELETE FROM rate_limits WHERE id = :id');
                $delete->execute(['id' => $record['id']]);
                $record = false;
            }

            if ($record === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rate_limits (rate_key, action, window_started_at, attempts, expires_at) VALUES (:rate_key, :action, :window_started_at, 1, :expires_at)'
                );
                $insert->execute([
                    'rate_key' => $key,
                    'action' => $action,
                    'window_started_at' => $nowSql,
                    'expires_at' => $expiresAt,
                ]);
                $this->pdo->commit();

                return true;
            }

            if ((int) $record['attempts'] >= $max) {
                $this->pdo->commit();

                return false;
            }

            $update = $this->pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = :id');
            $update->execute(['id' => $record['id']]);
            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function deleteExpired(string $nowSql): void
    {
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'DELETE FROM rate_limits WHERE expires_at <= :now ORDER BY id LIMIT 100'
            : 'DELETE FROM rate_limits WHERE id IN (SELECT id FROM rate_limits WHERE expires_at <= :now ORDER BY id LIMIT 100)';
        $delete = $this->pdo->prepare($sql);
        $delete->execute(['now' => $nowSql]);
    }

    private function lockClause(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
