<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\RenderArtifactCleanupStore;
use App\Queue\ClaimedJob;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class RenderArtifactCleanupRepository implements RenderArtifactCleanupStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function reserve(ClaimedJob $job, array $objectKeys): bool
    {
        $keys = $this->exactKeys($objectKeys);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lease = $this->pdo->prepare(
                "SELECT leased_until FROM processing_jobs\n"
                . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
                . "AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()\n"
                . 'FOR UPDATE'
            );
            $lease->execute($this->leaseParams($job));
            $leasedUntil = $lease->fetchColumn();
            if (!is_string($leasedUntil) || $leasedUntil === '') {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO render_artifact_cleanups '
                . '(object_key, job_id, lease_token_hash, cleanup_after) '
                . 'VALUES (:object_key, :job_id, :lease_token_hash, :cleanup_after)'
            );
            foreach ($keys as $key) {
                $insert->execute([
                    'object_key' => $key,
                    'job_id' => $job->id(),
                    'lease_token_hash' => hash('sha256', $job->leaseToken()),
                    'cleanup_after' => $leasedUntil,
                ]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markForCleanup(ClaimedJob $job, array $objectKeys): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE render_artifact_cleanups SET cleanup_after = UTC_TIMESTAMP() '
            . 'WHERE object_key = :object_key AND job_id = :job_id AND lease_token_hash = :lease_token_hash'
        );
        foreach ($this->exactKeys($objectKeys) as $key) {
            $statement->execute($this->keyLeaseParams($job, $key));
        }
    }

    public function release(ClaimedJob $job, array $objectKeys): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM render_artifact_cleanups '
            . 'WHERE object_key = :object_key AND job_id = :job_id AND lease_token_hash = :lease_token_hash'
        );
        $deleted = 0;
        foreach ($this->exactKeys($objectKeys) as $key) {
            $statement->execute($this->keyLeaseParams($job, $key));
            $deleted += $statement->rowCount();
        }
        if ($deleted !== 2) {
            throw new RuntimeException('Render artifact reservations could not be released.');
        }
    }

    public function pending(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Cleanup batch limit must be between 1 and 100.');
        }
        $statement = $this->pdo->query(
            'SELECT object_key FROM render_artifact_cleanups '
            . 'WHERE cleanup_after IS NULL OR cleanup_after < UTC_TIMESTAMP() '
            . 'ORDER BY created_at ASC, object_key ASC LIMIT ' . $limit
        );

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function forget(string $objectKey): void
    {
        $this->assertObjectKey($objectKey);
        $statement = $this->pdo->prepare('DELETE FROM render_artifact_cleanups WHERE object_key = :object_key');
        $statement->execute(['object_key' => $objectKey]);
    }

    private function assertObjectKey(string $objectKey): void
    {
        if (preg_match(
            '#\A(?:processed/[1-9][0-9]*/[1-9][0-9]*-[a-f0-9]{32}\.mp4|thumbnails/[1-9][0-9]*/[1-9][0-9]*-[a-f0-9]{32}\.jpg)\z#D',
            $objectKey
        ) !== 1) {
            throw new InvalidArgumentException('Render artifact cleanup key is invalid.');
        }
    }

    /** @param list<string> $objectKeys @return array{0:string,1:string} */
    private function exactKeys(array $objectKeys): array
    {
        if (count($objectKeys) !== 2 || !is_string($objectKeys[0] ?? null) || !is_string($objectKeys[1] ?? null)
            || $objectKeys[0] === $objectKeys[1]
        ) {
            throw new InvalidArgumentException('Exactly two distinct render artifact cleanup keys are required.');
        }
        $this->assertObjectKey($objectKeys[0]);
        $this->assertObjectKey($objectKeys[1]);

        return [$objectKeys[0], $objectKeys[1]];
    }

    /** @return array{id:int,worker_id:string,lease_token_hash:string} */
    private function leaseParams(ClaimedJob $job): array
    {
        return [
            'id' => $job->id(),
            'worker_id' => $job->workerId(),
            'lease_token_hash' => hash('sha256', $job->leaseToken()),
        ];
    }

    /** @return array{object_key:string,job_id:int,lease_token_hash:string} */
    private function keyLeaseParams(ClaimedJob $job, string $key): array
    {
        return [
            'object_key' => $key,
            'job_id' => $job->id(),
            'lease_token_hash' => hash('sha256', $job->leaseToken()),
        ];
    }
}
