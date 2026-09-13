<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Queue\ClaimedJob;
use App\Queue\JobRepository;
use App\Queue\ProcessingErrorCatalog;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ProcessingJobRepository implements JobRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        if (trim($queue) === '' || trim($workerId) === '' || $leaseSeconds < 1) {
            throw new InvalidArgumentException('Queue, worker and lease duration are required.');
        }

        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                "SELECT id, status, queue_name, type, project_id, payload_json, attempts, max_attempts\n"
                . "FROM processing_jobs\n"
                . "WHERE queue_name = :queue\n"
                . "  AND ((status IN ('queued', 'retry') AND available_at <= UTC_TIMESTAMP())\n"
                . "       OR (status = 'running' AND leased_until < UTC_TIMESTAMP() AND attempts < max_attempts))\n"
                . "ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $select->execute(['queue' => $queue]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $this->pdo->commit();

                return null;
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $update = $this->pdo->prepare(
                "UPDATE processing_jobs\n"
                . "SET status = 'running', attempts = attempts + 1, worker_id = :worker_id,\n"
                . "    lease_token_hash = :lease_token_hash, leased_until = UTC_TIMESTAMP() + INTERVAL :lease_seconds SECOND,\n"
                . "    started_at = COALESCE(started_at, UTC_TIMESTAMP()), finished_at = NULL\n"
                . "WHERE id = :id"
            );
            $update->execute([
                'worker_id' => $workerId,
                'lease_token_hash' => $tokenHash,
                'lease_seconds' => $leaseSeconds,
                'id' => (int) $row['id'],
            ]);
            $this->pdo->commit();

            return new ClaimedJob(
                (int) $row['id'],
                (string) $row['queue_name'],
                (string) $row['type'],
                (int) $row['project_id'],
                $this->decodePayload((string) $row['payload_json']),
                $workerId,
                $token,
                (int) $row['attempts'] + 1,
                (int) $row['max_attempts']
            );
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function failOneExpiredExhausted(string $queue): bool
    {
        if (trim($queue) === '') {
            throw new InvalidArgumentException('Queue is required.');
        }

        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                "SELECT j.id, p.status, p.error_code, p.error_message FROM processing_jobs j\n"
                . "INNER JOIN projects p ON p.id = j.project_id\n"
                . "WHERE j.queue_name = :queue AND j.status = 'running' AND j.leased_until < UTC_TIMESTAMP()\n"
                . "  AND j.attempts >= j.max_attempts\n"
                . "ORDER BY j.id ASC LIMIT 1 FOR UPDATE"
            );
            $select->execute(['queue' => $queue]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();

                return false;
            }

            $jobId = (int) $row['id'];
            if (in_array((string) $row['status'], ['ready', 'suggestions_ready'], true)) {
                $completed = $this->pdo->prepare(
                    "UPDATE processing_jobs SET status = 'completed', progress = 100, finished_at = UTC_TIMESTAMP(),\n"
                    . "last_error_code = NULL, last_error_message = NULL, worker_id = NULL, lease_token_hash = NULL, leased_until = NULL\n"
                    . "WHERE id = :id AND status = 'running' AND leased_until < UTC_TIMESTAMP() AND attempts >= max_attempts"
                );
                $completed->execute(['id' => $jobId]);
                $this->pdo->commit();

                return false;
            }
            if ((string) $row['status'] === 'failed') {
                $code = is_string($row['error_code']) && $row['error_code'] !== '' ? $row['error_code'] : 'worker_error';
                $message = is_string($row['error_message']) && $row['error_message'] !== '' ? $row['error_message'] : ProcessingErrorCatalog::requireMessage('worker_error');
                $this->assertPublicError($code, $message);
                $terminal = $this->pdo->prepare(
                    "UPDATE processing_jobs SET status = 'failed', finished_at = UTC_TIMESTAMP(),\n"
                    . "last_error_code = :code, last_error_message = :message, worker_id = NULL,\n"
                    . "lease_token_hash = NULL, leased_until = NULL\n"
                    . "WHERE id = :id AND status = 'running' AND leased_until < UTC_TIMESTAMP() AND attempts >= max_attempts"
                );
                $terminal->execute(['id' => $jobId, 'code' => $code, 'message' => $message]);
                $this->pdo->commit();

                return $terminal->rowCount() === 1;
            }

            $deferred = $this->pdo->prepare(
                "UPDATE processing_jobs SET status = 'retry', available_at = UTC_TIMESTAMP() + INTERVAL 15 SECOND,\n"
                . "last_error_code = 'processing_persistence_failed',\n"
                . "last_error_message = 'Não foi possível salvar o processamento agora. Tente novamente.',\n"
                . "worker_id = NULL, lease_token_hash = NULL, leased_until = NULL\n"
                . "WHERE id = :id AND status = 'running' AND leased_until < UTC_TIMESTAMP() AND attempts >= max_attempts"
            );
            $deferred->execute(['id' => $jobId]);
            $this->pdo->commit();

            return false;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
    public function complete(ClaimedJob $job): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE processing_jobs SET status = 'completed', progress = 100, finished_at = UTC_TIMESTAMP(),\n"
            . "last_error_code = NULL, last_error_message = NULL, worker_id = NULL, lease_token_hash = NULL, leased_until = NULL\n"
            . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
            . "  AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()"
        );

        $statement->execute($this->leaseParameters($job));

        return $statement->rowCount() === 1;
    }

    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool
    {
        $this->assertPublicError($code, $publicMessage);

        $this->pdo->beginTransaction();

        try {
            $terminal = $this->pdo->prepare(
                "UPDATE processing_jobs SET status = 'failed', finished_at = UTC_TIMESTAMP(),\n"
                . "last_error_code = :code, last_error_message = :message, worker_id = NULL,\n"
                . "lease_token_hash = NULL, leased_until = NULL\n"
                . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
                . "  AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()\n"
                . "  AND attempts >= max_attempts"
            );
            $terminal->execute($this->transitionParameters($job, $code, $publicMessage));
            if ($terminal->rowCount() === 1) {
                $this->pdo->commit();

                return true;
            }

            $retry = $this->pdo->prepare(
                "UPDATE processing_jobs SET status = 'retry', available_at = :available_at,\n"
                . "last_error_code = :code, last_error_message = :message, worker_id = NULL,\n"
                . "lease_token_hash = NULL, leased_until = NULL\n"
                . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
                . "  AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()\n"
                . "  AND attempts < max_attempts"
            );
            $parameters = $this->transitionParameters($job, $code, $publicMessage);
            $parameters['available_at'] = $availableAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $retry->execute($parameters);
            $this->pdo->commit();

            return $retry->rowCount() === 1;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function defer(ClaimedJob $job, DateTimeImmutable $availableAt): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE processing_jobs SET status = 'retry', attempts = GREATEST(0, attempts - 1), available_at = :available_at,\n"
            . "last_error_code = NULL, last_error_message = NULL, worker_id = NULL,\n"
            . "lease_token_hash = NULL, leased_until = NULL\n"
            . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
            . "  AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()"
        );
        $parameters = $this->leaseParameters($job);
        $parameters['available_at'] = $availableAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $statement->execute($parameters);

        return $statement->rowCount() === 1;
    }

    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool
    {
        $this->assertPublicError($code, $publicMessage);
        $statement = $this->pdo->prepare(
            "UPDATE processing_jobs SET status = 'failed', finished_at = UTC_TIMESTAMP(),\n"
            . "last_error_code = :code, last_error_message = :message, worker_id = NULL,\n"
            . "lease_token_hash = NULL, leased_until = NULL\n"
            . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
            . "  AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()"
        );
        $statement->execute($this->transitionParameters($job, $code, $publicMessage));

        return $statement->rowCount() === 1;
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Processing job payload is invalid.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Processing job payload must be an object.');
        }

        return $decoded;
    }

    /** @return array<string, int|string> */
    private function leaseParameters(ClaimedJob $job): array
    {
        return [
            'id' => $job->id(),
            'worker_id' => $job->workerId(),
            'lease_token_hash' => hash('sha256', $job->leaseToken()),
        ];
    }

    /** @return array<string, int|string> */
    private function transitionParameters(ClaimedJob $job, string $code, string $message): array
    {
        return $this->leaseParameters($job) + ['code' => $code, 'message' => $message];
    }

    private function assertPublicError(string $code, string $message): void
    {
        ProcessingErrorCatalog::assert($code, $message);
    }
}
