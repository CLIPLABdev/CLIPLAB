<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JobDispatcher;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class DatabaseJobDispatcher implements JobDispatcher
{
    public function __construct(private PDO $pdo, private string $queue = 'media', private int $maxAttempts = 3)
    {
        if (trim($queue) === '' || $maxAttempts < 1) {
            throw new InvalidArgumentException('Queue and maximum attempts must be valid.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
    {
        if (trim($type) === '' || strlen($type) > 64 || $projectId <= 0 || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Job type, project and idempotency key are required.');
        }

        try {
            $payloadJson = json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Job payload cannot be encoded.', 0, $exception);
        }

        $hash = hash('sha256', $idempotencyKey);
        $existing = $this->findExisting($hash);
        if ($existing !== null) {
            return $this->matchingId($existing, $type, $projectId, $payloadJson);
        }

        $parameters = [
            'queue_name' => $this->queue,
            'type' => $type,
            'project_id' => $projectId,
            'payload_json' => $payloadJson,
            'idempotency_key' => $hash,
            'max_attempts' => $this->maxAttempts,
        ];
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO processing_jobs (queue_name, type, project_id, payload_json, idempotency_key, max_attempts, available_at)\n"
                . "VALUES (:queue_name, :type, :project_id, :payload_json, :idempotency_key, :max_attempts, UTC_TIMESTAMP())"
            );
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->findExisting($hash, true);
            if ($existing === null) {
                throw $exception;
            }

            return $this->matchingId($existing, $type, $projectId, $payloadJson);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    private function findExisting(string $hash, bool $currentRead = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, queue_name, type, project_id, payload_json, max_attempts
             FROM processing_jobs
             WHERE queue_name = :queue_name AND idempotency_key = :idempotency_key
             LIMIT 1'
             . ($currentRead && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' LOCK IN SHARE MODE'
                : '')
        );
        $statement->execute(['queue_name' => $this->queue, 'idempotency_key' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function matchingId(array $row, string $type, int $projectId, string $payloadJson): int
    {
        try {
            $stored = json_decode((string) ($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $storedJson = json_encode($this->canonicalize($stored), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Job idempotency conflict.', 0, $exception);
        }
        if (($row['queue_name'] ?? null) !== $this->queue
            || ($row['type'] ?? null) !== $type
            || (int) ($row['project_id'] ?? 0) !== $projectId
            // An analysis keeps the finite budget stored at creation even after configuration changes.
            || ($type !== 'analyze_video' && (int) ($row['max_attempts'] ?? 0) !== $this->maxAttempts)
            || !hash_equals($payloadJson, (string) $storedJson)
        ) {
            throw new RuntimeException('Job idempotency conflict.');
        }

        return (int) $row['id'];
    }

    /** @return mixed */
    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
