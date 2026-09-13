<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SourceArtifactCleanupStore;
use App\Queue\ClaimedJob;
use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class SourceArtifactCleanupRepository implements SourceArtifactCleanupStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function reserve(string $objectKey, ?ClaimedJob $job = null): bool
    {
        $this->assertKey($objectKey);
        $this->requireNoTransaction();
        if (($job === null && !str_starts_with($objectKey,'users/'))
            || ($job !== null && ($job->type() !== 'fetch_and_probe' || !str_starts_with($objectKey,'imports/'.$job->projectId().'/')))) {
            throw new InvalidArgumentException('Source reservation does not match its owner or job.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($job === null) {
                $statement=$this->pdo->prepare('INSERT INTO source_artifact_cleanups (object_key,cleanup_after) VALUES (:object_key,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))');
                $statement->execute(['object_key'=>$objectKey]);
            } else {
                $lease=$this->pdo->prepare("SELECT leased_until FROM processing_jobs WHERE id=:id AND project_id=:project_id AND type='fetch_and_probe' AND status='running' AND worker_id=:worker_id AND lease_token_hash=:lease_hash AND leased_until>UTC_TIMESTAMP() FOR UPDATE");
                $lease->execute(['id'=>$job->id(),'project_id'=>$job->projectId(),'worker_id'=>$job->workerId(),'lease_hash'=>hash('sha256',$job->leaseToken())]);
                $until=$lease->fetchColumn();
                if (!is_string($until) || $until==='') {
                    $this->pdo->rollBack();
                    return false;
                }
                $statement=$this->pdo->prepare('INSERT INTO source_artifact_cleanups (object_key,job_id,lease_token_hash,cleanup_after) VALUES (:object_key,:job_id,:lease_hash,:cleanup_after)');
                $statement->execute(['object_key'=>$objectKey,'job_id'=>$job->id(),'lease_hash'=>hash('sha256',$job->leaseToken()),'cleanup_after'=>$until]);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            $this->rollbackWithoutMasking();
            throw $error;
        }
    }

    public function lockForPublication(string $objectKey): void
    {
        $this->assertKey($objectKey);
        $this->requireTransaction();
        $statement=$this->pdo->prepare('SELECT cleanup_after>UTC_TIMESTAMP() AS publishable FROM source_artifact_cleanups WHERE object_key=:object_key FOR UPDATE');
        $statement->execute(['object_key'=>$objectKey]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Source reservation is missing or expired.');
        }
    }

    public function release(string $objectKey): void
    {
        $this->lockForPublication($objectKey);
        if (!$this->referenced($objectKey)) {
            throw new RuntimeException('Source publication must precede reservation release.');
        }
        $this->forget($objectKey);
    }

    public function pending(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 25) {
            throw new InvalidArgumentException('Source cleanup batch must be between 1 and 25.');
        }
        $statement=$this->pdo->query('SELECT object_key FROM source_artifact_cleanups WHERE cleanup_after<=UTC_TIMESTAMP() ORDER BY cleanup_after,object_key LIMIT '.$limit);
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function discard(string $objectKey, callable $delete): void
    {
        $this->assertKey($objectKey);
        $this->requireNoTransaction();
        // A slow writer can finish after GC. Recreate/expire its obligation durably before cleanup.
        // If publication actually committed, the locked reference check below protects its bytes.
        $this->pdo->beginTransaction();
        try {
            $statement=$this->pdo->prepare('INSERT INTO source_artifact_cleanups (object_key,cleanup_after) VALUES (:object_key,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE cleanup_after=LEAST(cleanup_after,UTC_TIMESTAMP())');
            $statement->execute(['object_key'=>$objectKey]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollbackWithoutMasking();
            throw $error;
        }
        $this->cleanup($objectKey,$delete,false);
    }

    public function cleanupExpired(string $objectKey, callable $delete): void
    {
        $this->assertKey($objectKey);
        $this->requireNoTransaction();
        $this->cleanup($objectKey,$delete,true);
    }

    private function cleanup(string $objectKey, callable $delete, bool $expiredOnly): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement=$this->pdo->prepare('SELECT cleanup_after<=UTC_TIMESTAMP() AS expired FROM source_artifact_cleanups WHERE object_key=:object_key FOR UPDATE');
            $statement->execute(['object_key'=>$objectKey]);
            $expired=$statement->fetchColumn();
            if ($expired !== false && (!$expiredOnly || (int)$expired === 1)) {
                if (!$this->referenced($objectKey)) {
                    $delete(); // Keep the row locked until deletion and forgetting commit together.
                }
                $this->forget($objectKey);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollbackWithoutMasking();
            throw $error;
        }
    }

    private function referenced(string $objectKey): bool
    {
        $statement=$this->pdo->prepare('SELECT id FROM project_sources WHERE object_key=:object_key LIMIT 1 FOR UPDATE');
        $statement->execute(['object_key'=>$objectKey]);
        return $statement->fetchColumn() !== false;
    }

    private function forget(string $objectKey): void
    {
        $statement=$this->pdo->prepare('DELETE FROM source_artifact_cleanups WHERE object_key=:object_key');
        $statement->execute(['object_key'=>$objectKey]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Source reservation could not be released.');
    }

    private function assertKey(string $objectKey): void
    {
        if (strlen($objectKey)>255 || preg_match('#\A(?:users/[1-9][0-9]*/uploads|imports/[1-9][0-9]*)/[a-f0-9]{32}\.(?:mp4|mov|webm)\z#D',$objectKey)!==1) {
            throw new InvalidArgumentException('Source cleanup key is invalid.');
        }
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Source publication requires a transaction.');
    }

    private function requireNoTransaction(): void
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Source reservation or cleanup requires its own committed transaction.');
    }

    private function rollbackWithoutMasking(): void
    {
        try { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); } catch (Throwable) { /* Retain the original failure. */ }
    }
}
