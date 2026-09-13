<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiAnalysisReceipt;
use App\Contracts\AiPipelineScheduler;
use App\Contracts\JobDispatcher;
use App\Credits\InsufficientCredits;
use App\Plans\PlanLimitExceeded;
use App\Queue\ProcessingErrorCatalog;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AiPipelineStarter implements AiPipelineScheduler
{
    public function __construct(
        private PDO $pdo,
        private ProjectRepository $projects,
        private ProjectSourceRepository $sources,
        private AiAnalysisRepository $analyses,
        private CreditReservationService $credits,
        private JobDispatcher $jobs,
        private string $promptVersion,
        private string $model,
        private ?PlanQuotaService $quotas = null
    ) {
        if (trim($promptVersion) === '' || strlen($promptVersion) > 32
            || trim($model) === '' || strlen($model) > 100
            || preg_match('/[\x00-\x1F\x7F]/', $promptVersion . $model) === 1
        ) {
            throw new InvalidArgumentException('AI pipeline identity is invalid.');
        }
    }

    public function schedule(int $projectId, int $sourceId, int $durationSeconds): AiAnalysisReceipt
    {
        if ($projectId < 1 || $sourceId < 1 || $durationSeconds < 1 || $durationSeconds > 86400) {
            throw new InvalidArgumentException('AI pipeline source is invalid.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction && !$this->pdo->beginTransaction()) {
            throw new RuntimeException('AI pipeline transaction could not start.');
        }
        try {
            $ownerId = $this->projects->ownerId($projectId);
            $ready = $this->sources->findReadyForAnalysis($sourceId, $projectId);
            if ($ownerId === null || $ready === null || $ready['duration_seconds'] !== $durationSeconds) {
                throw new InvalidArgumentException('AI pipeline source or owner does not match.');
            }
            if ($this->quotas !== null) {
                try {
                    $this->quotas->assertProcessingMinutesAvailable($ownerId, $projectId, $durationSeconds);
                } catch (PlanLimitExceeded $exception) {
                    $analysis = $this->analyses->createOrFind($projectId, $this->promptVersion, $this->model);
                    $this->projects->advanceProcessingState(
                        $projectId,
                        'failed',
                        $exception->errorCode(),
                        ProcessingErrorCatalog::message($exception->errorCode())
                            ?? 'O plano atual não permite iniciar este processamento.'
                    );
                    if ($ownsTransaction) {
                        $this->pdo->commit();
                    }

                    return new AiAnalysisReceipt(
                        $analysis->analysisId(),
                        null,
                        $analysis->status(),
                        $analysis->created()
                    );
                }
            }
            $units = $this->credits->costForDuration($durationSeconds);
            $logicalKey = 'ai:analyze:' . $projectId . ':' . $this->promptVersion;
            try {
                $reservation = $this->credits->reserve($ownerId, $projectId, $units, $logicalKey);
            } catch (InsufficientCredits) {
                $analysis = $this->analyses->createOrFind($projectId, $this->promptVersion, $this->model);
                $this->projects->advanceProcessingState(
                    $projectId,
                    'awaiting_credits',
                    'insufficient_credits',
                    'Créditos insuficientes para iniciar a análise.'
                );
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return new AiAnalysisReceipt(
                    $analysis->analysisId(),
                    null,
                    $analysis->status(),
                    $analysis->created()
                );
            }

            if ($this->quotas !== null) {
                $this->quotas->recordProcessingUsage($ownerId, $projectId, $durationSeconds);
            }

            $analysis = $this->analyses->createOrFind($projectId, $this->promptVersion, $this->model);
            $this->jobs->dispatch('analyze_video', $projectId, [
                'analysis_id' => $analysis->analysisId(),
                'source_id' => $sourceId,
                'reservation_id' => $reservation->id(),
            ], $logicalKey);
            $this->projects->advanceProcessingState($projectId, 'ai_queued');
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return new AiAnalysisReceipt(
                $analysis->analysisId(),
                $reservation->id(),
                $analysis->status(),
                $analysis->created()
            );
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
