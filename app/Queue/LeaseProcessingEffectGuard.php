<?php

declare(strict_types=1);

namespace App\Queue;

use PDO;
use Throwable;

final class LeaseProcessingEffectGuard implements ProcessingEffectGuard
{
    public function __construct(private PDO $pdo)
    {
    }

    public function apply(ClaimedJob $job, callable $effect): bool
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                "SELECT id FROM processing_jobs\n"
                . "WHERE id = :id AND status = 'running' AND worker_id = :worker_id\n"
                . "  AND lease_token_hash = :lease_token_hash AND leased_until >= UTC_TIMESTAMP()\n"
                . "FOR UPDATE"
            );
            $statement->execute([
                'id' => $job->id(),
                'worker_id' => $job->workerId(),
                'lease_token_hash' => hash('sha256', $job->leaseToken()),
            ]);
            if ($statement->fetchColumn() === false) {
                $this->pdo->rollBack();

                return false;
            }

            $effect();
            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
