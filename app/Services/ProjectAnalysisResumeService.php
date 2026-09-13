<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\AiPipelineScheduler;
use App\Ai\ViralClipPrompt;
use App\Repositories\ProjectSourceRepository;
use PDO;

final class ProjectAnalysisResumeService
{
    /** @param callable(?string): AiPipelineScheduler $scheduler Receives the existing analysis model, when present. */
    public function __construct(private PDO $pdo, private $scheduler, private ?AiAnalysisRecoveryService $recovery = null) {}

    public function resume(int $projectId, int $userId, ?int $expectedRefundId = null): ?string
    {
        if ($projectId < 1 || $userId < 1) return null;
        if ($expectedRefundId !== null) return $this->recovery?->recover($projectId,$userId,$expectedRefundId);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            // Match quota/reservation lock order: account, then project.
            // SQLite needs a write lock before reading for concurrent requests.
            $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            if (!$mysql) {
                $lock = $this->pdo->prepare('UPDATE users SET credits = credits WHERE id = ?');
                $lock->execute([$userId]);
            }
            $account = $this->pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'" . ($mysql ? ' FOR UPDATE' : ''));
            $account->execute([$userId]);
            $status = null;
            if ($account->fetchColumn() !== false) {
                $project = $this->pdo->prepare('SELECT status FROM projects WHERE id = ? AND user_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
                $project->execute([$projectId, $userId]);
                $value = $project->fetchColumn();
                $status = $value === false ? null : (string) $value;
                if ($status === 'awaiting_credits') {
                    $source = (new ProjectSourceRepository($this->pdo))->findReadyIdentityForOwnedProject($projectId, $userId, true);
                    if ($source === null) {
                        $status = 'source_unavailable';
                    } else {
                        // Waiting for credits already creates an analysis. Keep its
                        // identity even if the administrator changes the default model.
                        $analysis = $this->pdo->prepare('SELECT model FROM ai_analyses WHERE project_id = ? AND prompt_version = ?' . ($mysql ? ' FOR UPDATE' : ''));
                        $analysis->execute([$projectId, ViralClipPrompt::VERSION]);
                        $existingModel = $analysis->fetchColumn();
                        ($this->scheduler)($existingModel === false ? null : (string) $existingModel)
                            ->schedule($projectId, $source['id'], $source['duration_seconds']);
                        $project->execute([$projectId, $userId]);
                        $status = (string) $project->fetchColumn();
                    }
                }
            }
            if ($ownsTransaction) $this->pdo->commit();
            return $status;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}
