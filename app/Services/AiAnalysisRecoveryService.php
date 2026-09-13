<?php
declare(strict_types=1);
namespace App\Services;

use App\Ai\ViralClipPrompt;
use App\Plans\PlanLimitExceeded;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProjectSourceRepository;
use PDO;
use RuntimeException;

/** Explicit recovery of a terminal, refunded analysis attempt; ordinary reserve remains idempotent. */
final class AiAnalysisRecoveryService
{
    private const RECOVERABLE = ['ai_unavailable', 'ai_timeout', 'ai_rate_limited', 'ai_response_invalid'];

    public function __construct(private PDO $pdo, private int $creditsPerMinute = 1, private int $maxAttempts = 6)
    {
        if ($creditsPerMinute < 1) throw new \InvalidArgumentException('Invalid analysis credit rate.');
        $this->maxAttempts = max(1, min(8, $maxAttempts));
    }

    public function recoveryToken(int $projectId, int $userId): ?int
    {
        if ($projectId < 1 || $userId < 1) return null;
        $snapshot = $this->snapshot($projectId, $userId, false);
        return $snapshot === null ? null : (int) $snapshot['reservation']['refund_transaction_id'];
    }

    public function recover(int $projectId, int $userId, int $expectedRefundId): ?string
    {
        if ($projectId < 1 || $userId < 1 || $expectedRefundId < 1) return null;
        if ($this->pdo->inTransaction()) throw new \LogicException('Recovery must own its transaction and lock order.');
        $this->pdo->beginTransaction();
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                $lock = $this->pdo->prepare('UPDATE users SET credits = credits WHERE id = ?');
                $lock->execute([$userId]);
            }
            // A worker first fences its job, then touches the account. Follow that order.
            $snapshot = $this->snapshot($projectId, $userId, true);
            if ($snapshot === null) {
                $project = $this->row('SELECT status FROM projects WHERE id = ? AND user_id = ?', [$projectId,$userId]);
                $outcome = $project === null ? null : ($project['status'] === 'failed' ? 'recovery_unavailable' : (string)$project['status']);
            } elseif ((int)$snapshot['reservation']['refund_transaction_id'] !== $expectedRefundId) {
                $outcome = 'recovery_stale';
            } else {
                $outcome = $this->rearm($snapshot, $projectId, $userId, $expectedRefundId);
            }
            $this->pdo->commit();
            return $outcome;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function snapshot(int $projectId, int $userId, bool $lock): ?array
    {
        $key = 'ai:analyze:'.$projectId.':'.ViralClipPrompt::VERSION;
        $job = $this->row("SELECT * FROM processing_jobs WHERE project_id = ? AND type = 'analyze_video' AND queue_name = 'media' AND idempotency_key = ?",[$projectId,hash('sha256',$key)],$lock);
        if ($job === null || $job['status'] !== 'failed' || $job['worker_id'] !== null
            || $job['lease_token_hash'] !== null || $job['leased_until'] !== null
            || $job['finished_at'] === null || !in_array($job['last_error_code'],self::RECOVERABLE,true)) return null;
        $account = $this->row("SELECT id,credits FROM users WHERE id = ? AND status = 'active'",[$userId],$lock);
        if ($account === null) return null;
        try { $payload = json_decode((string)$job['payload_json'],true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!is_array($payload)) return null;
        $keys = array_keys($payload); sort($keys);
        if ($keys !== ['analysis_id','reservation_id','source_id']) return null;
        foreach ($payload as $id) if (!is_int($id) || $id < 1) return null;
        $reservation = $this->row('SELECT * FROM credit_reservations WHERE id = ? AND user_id = ? AND project_id = ?',[$payload['reservation_id'],$userId,$projectId],$lock);
        if ($reservation === null || $reservation['status'] !== 'refunded' || $reservation['operation'] !== 'ai_analysis'
            || !hash_equals(hash('sha256',$userId.':'.$key),(string)$reservation['idempotency_key'])
            || (int)$reservation['refund_transaction_id'] < 1 || (int)$reservation['credit_transaction_id'] < 1
            || $reservation['consumed_at'] !== null || $reservation['refunded_at'] === null) return null;
        $analysis = $this->row('SELECT * FROM ai_analyses WHERE id = ? AND project_id = ?',[$payload['analysis_id'],$projectId],$lock);
        if ($analysis === null || $analysis['prompt_version'] !== ViralClipPrompt::VERSION || $analysis['status'] !== 'failed'
            || $analysis['error_code'] !== $job['last_error_code'] || $analysis['validated_response_json'] !== null
            || $analysis['video_summary'] !== null || $analysis['completed_at'] !== null) return null;
        $project = $this->row('SELECT * FROM projects WHERE id = ? AND user_id = ?',[$projectId,$userId],$lock);
        if ($project === null || $project['status'] !== 'failed' || $project['error_code'] !== $job['last_error_code']) return null;
        $source = (new ProjectSourceRepository($this->pdo))->findReadyIdentityForOwnedProject($projectId,$userId,$lock);
        if ($source === null || $source['id'] !== $payload['source_id']
            || (int)$project['processed_duration_seconds'] !== $source['duration_seconds']
            || $project['usage_recorded_at'] === null
            || (int)$reservation['units'] !== (intdiv($source['duration_seconds']-1,60)+1)*$this->creditsPerMinute) return null;
        if ($this->row('SELECT id FROM clips WHERE project_id = ? LIMIT 1',[$projectId],$lock) !== null) return null;
        // Both original financial entries must belong to this reservation and owner.
        foreach (['credit_transaction_id'=>'debit','refund_transaction_id'=>'credit'] as $field=>$type) {
            $entry = $this->row('SELECT user_id,type,amount,reference_type,reference_id FROM credit_transactions WHERE id = ?',[(int)$reservation[$field]],$lock);
            if ($entry === null || (int)$entry['user_id'] !== $userId || $entry['type'] !== $type
                || (int)$entry['amount'] !== (int)$reservation['units'] || $entry['reference_type'] !== 'credit_reservation'
                || (int)$entry['reference_id'] !== (int)$reservation['id']) return null;
        }
        return compact('job','account','reservation','analysis','project','source');
    }

    private function rearm(array $snapshot, int $projectId, int $userId, int $expectedRefundId): string
    {
        try {
            (new PlanQuotaService($this->pdo))->assertProcessingMinutesAvailable($userId,$projectId,$snapshot['source']['duration_seconds']);
        } catch (PlanLimitExceeded) { return 'recovery_quota_blocked'; }
        $ledger = new CreditTransactionRepository($this->pdo);
        $available = $ledger->latestLockedBalanceForUser($userId) ?? (int)$snapshot['account']['credits'];
        $units = (int)$snapshot['reservation']['units'];
        if ($available < $units) return 'insufficient_credits';
        $reservationId = (int)$snapshot['reservation']['id'];
        $debitId = $ledger->recordReservationEntry($userId,'debit',$units,$available-$units,$reservationId,
            'Retomada de análise após estorno '.$expectedRefundId);
        $this->update("UPDATE credit_reservations SET status='reserved',credit_transaction_id=?,refund_transaction_id=NULL,refunded_at=NULL
            WHERE id=? AND status='refunded' AND refund_transaction_id=? AND consumed_at IS NULL",[$debitId,$reservationId,$expectedRefundId]);
        (new CreditReservationRepository($this->pdo))->updateUserBalance($userId,$available-$units);
        // A new provider upload uses the same local source, including after remote expiration.
        $this->update("UPDATE ai_analyses SET status='queued',error_code=NULL,error_message=NULL,validation_attempts=0,
            gemini_file_name=NULL,gemini_file_uri=NULL,gemini_file_mime=NULL,gemini_file_state=NULL
            WHERE id=? AND status='failed' AND validated_response_json IS NULL",[(int)$snapshot['analysis']['id']]);
        $this->update("UPDATE projects SET status='ai_queued',progress=75,error_code=NULL,error_message=NULL
            WHERE id=? AND user_id=? AND status='failed'",[$projectId,$userId]);
        $this->update("UPDATE processing_jobs SET status='queued',attempts=0,max_attempts=?,progress=0,
            available_at=UTC_TIMESTAMP(),started_at=NULL,finished_at=NULL,last_error_code=NULL,last_error_message=NULL,
            worker_id=NULL,lease_token_hash=NULL,leased_until=NULL WHERE id=? AND status='failed'
            AND lease_token_hash IS NULL AND worker_id IS NULL AND leased_until IS NULL",[$this->maxAttempts,(int)$snapshot['job']['id']]);
        return 'ai_queued';
    }

    private function row(string $sql, array $parameters, bool $lock = false): ?array
    {
        if ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
        $statement = $this->pdo->prepare($sql); $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function update(string $sql, array $parameters): void
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($parameters);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Analysis recovery lost its expected state.');
    }
}
