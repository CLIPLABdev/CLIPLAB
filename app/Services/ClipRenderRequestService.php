<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JobDispatcher;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Editor\EditorOptions;
use App\Media\PreciseSourceDuration;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipEditorRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ClipRenderRequestService
{
    private const DECIMAL_PATTERN = '/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?\z/D';
    private const MAX_RENDER_REVISION = 4294967295;

    public function __construct(
        private PDO $pdo,
        private ClipRepository $clips,
        private ProjectRepository $projects,
        private ClipRenderProfileRepository $profiles,
        private ReframePlanValidator $reframes,
        private JobDispatcher $jobs,
        private int $maxDurationSeconds = 180,
        private ?SourceDurationPreflight $sourceDurations = null,
        private ?ClipEditorRepository $editor = null
    ) {
        if ($maxDurationSeconds < 1 || $maxDurationSeconds > 180) {
            throw new \InvalidArgumentException('Render duration limit must be between 1 and 180 seconds.');
        }
        $this->editor ??= new ClipEditorRepository($pdo);
    }

    public function request(
        int $clipId,
        int $userId,
        string $startTime,
        string $endTime,
        ?ReframeSubmission $reframe = null,
        ?PreciseSourceDuration $prepared = null
    ): ?ClipRenderReceipt {
        if ($clipId < 1 || $userId < 1) {
            return null;
        }

        $before = $this->clips->findForRenderRequest($clipId,$userId);
        if ($before === null) return null;
        if ($prepared === null && in_array($before['status'],['suggested','failed'],true)) {
            try {
                if ($this->sourceDurations === null || $this->pdo->inTransaction()) {
                    throw new RuntimeException('Precise source measurement is unavailable.');
                }
                $prepared=$this->sourceDurations->prepare($before['project_id'],$userId);
                if ($prepared === null) throw new RuntimeException('Precise source measurement is unavailable.');
            } catch (Throwable) {
                throw $this->precisionError($before['project_id']);
            }
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Clip render transaction could not start.');
            }
        } else {
            $savepoint = 'clip_render_' . bin2hex(random_bytes(8));
            $this->executeSavepoint('SAVEPOINT', $savepoint);
        }

        try {
            $clip = $this->clips->findForRenderRequest($clipId, $userId, true);
            if ($clip === null) {
                $this->completeTransactionScope($ownsTransaction, $savepoint);

                return null;
            }

            if (in_array($clip['status'], ['queued', 'rendering'], true)) {
                $receipt = new ClipRenderReceipt(
                    $clip['id'],
                    $clip['project_id'],
                    $clip['render_revision'],
                    false
                );
                $this->completeTransactionScope($ownsTransaction, $savepoint);

                return $receipt;
            }

            if (!in_array($clip['status'], ['suggested', 'failed'], true)) {
                throw new ClipRenderValidationException([
                    'clip' => 'Este corte não pode ser renderizado novamente.',
                ], $clip['project_id']);
            }

            try {
                if ($prepared === null || $this->sourceDurations === null || $prepared->sourceId() !== $clip['source_id']) {
                    throw new RuntimeException('Precise source measurement is unavailable.');
                }
                $this->sourceDurations->assertCurrent($prepared,$clip['project_id'],$userId);
            } catch (Throwable) {
                throw $this->precisionError($clip['project_id']);
            }
            [$start, $end] = $this->validatedInterval(
                $startTime,
                $endTime,
                $prepared->milliseconds(),
                $clip['project_id']
            );
            if ($clip['render_revision'] >= self::MAX_RENDER_REVISION) {
                throw new ClipRenderValidationException([
                    'clip' => 'Este corte não pode ser renderizado novamente.',
                ], $clip['project_id']);
            }
            $durationMs = (int) round(($end - $start) * 1000);
            try {
                $plan = $this->reframes->validate($reframe ?? ReframeSubmission::original(), $durationMs);
            } catch (InvalidArgumentException) {
                throw new ClipRenderValidationException([
                    'reframe' => 'Configuração de enquadramento inválida.',
                ], $clip['project_id']);
            }
            $revision = $clip['render_revision'] + 1;

            if (!$clip['has_audio']) {
                throw new ClipRenderValidationException([
                    'form' => 'O vídeo não possui áudio para gerar legendas automáticas. Adicione uma legenda sincronizada pelo editor.',
                ], $clip['project_id']);
            }

            $this->profiles->create($clip['id'], $revision, $plan);
            $this->editor->createProfile(
                $clip['id'], $revision, null, $userId,
                hash('sha256', sprintf('automatic-captions:%d:v%d', $clip['id'], $revision)),
                EditorOptions::fromArray(['style' => 'minimal']), 'auto', $durationMs
            );
            $this->clips->queueRender($clip['id'], $start, $end, $revision);
            $payload = ['clip_id' => $clip['id'], 'render_revision' => $revision];
            $this->jobs->dispatch(
                'generate_subtitles',
                $clip['project_id'],
                $payload,
                sprintf('clip-subtitles:%d:v%d', $clip['id'], $revision)
            );
            $this->projects->synchronizeRenderState($clip['project_id']);
            $this->completeTransactionScope($ownsTransaction, $savepoint);

            return new ClipRenderReceipt($clip['id'], $clip['project_id'], $revision, true);
        } catch (Throwable $exception) {
            try {
                $this->rollbackTransactionScope($ownsTransaction, $savepoint);
            } catch (Throwable) {
                throw new RuntimeException('Clip render transaction rollback failed.', 0, $exception);
            }

            throw $exception;
        }
    }

    /** @return array{float, float} */
    private function validatedInterval(string $startTime, string $endTime, int $sourceDurationMs, int $projectId): array
    {
        $errors = [];
        if (preg_match(self::DECIMAL_PATTERN, $startTime) !== 1) {
            $errors['start_time'] = 'Informe um início válido.';
        }
        if (preg_match(self::DECIMAL_PATTERN, $endTime) !== 1) {
            $errors['end_time'] = 'Informe um fim válido.';
        }
        if ($errors !== []) {
            throw new ClipRenderValidationException($errors, $projectId);
        }

        $start = (float) $startTime;
        $end = (float) $endTime;
        $duration = $end - $start;
        if (!is_finite($start) || !is_finite($end)) {
            throw new ClipRenderValidationException([
                'end_time' => 'Informe um intervalo válido.',
            ], $projectId);
        }
        if ($end <= $start) {
            $errors['end_time'] = 'O fim deve ser maior que o início.';
        } elseif ($duration < 1.0) {
            $errors['end_time'] = 'O corte deve ter pelo menos 1 segundo.';
        } elseif ($duration > (float) $this->maxDurationSeconds) {
            $errors['end_time'] = 'O corte deve ter no máximo ' . $this->maxDurationSeconds . ' segundos.';
        } elseif ((int)round($end*1000) > $sourceDurationMs) {
            $errors['end_time'] = 'O fim do corte ultrapassa a duração do vídeo. Limite: '.number_format($sourceDurationMs/1000,3,'.','').' s.';
        }
        if ($errors !== []) {
            throw new ClipRenderValidationException($errors, $projectId);
        }

        return [$start, $end];
    }

    private function completeTransactionScope(bool $ownsTransaction, ?string $savepoint): void
    {
        if ($ownsTransaction && !$this->pdo->commit()) {
            throw new RuntimeException('Clip render transaction could not commit.');
        }
        if (!$ownsTransaction && $savepoint !== null) {
            $this->executeSavepoint('RELEASE SAVEPOINT', $savepoint);
        }
    }

    private function precisionError(int $projectId): ClipRenderValidationException
    {
        return new ClipRenderValidationException(['end_time'=>'Não foi possível confirmar a duração precisa do vídeo. Tente novamente.'],$projectId);
    }

    private function rollbackTransactionScope(bool $ownsTransaction, ?string $savepoint): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction() && !$this->pdo->rollBack()) {
                throw new RuntimeException('Clip render transaction could not roll back.');
            }

            return;
        }
        if ($savepoint === null || !$this->pdo->inTransaction()) {
            throw new RuntimeException('Clip render transaction scope was lost.');
        }

        $this->executeSavepoint('ROLLBACK TO SAVEPOINT', $savepoint);
        $this->executeSavepoint('RELEASE SAVEPOINT', $savepoint);
    }

    private function executeSavepoint(string $operation, string $savepoint): void
    {
        if ($this->pdo->exec($operation . ' ' . $savepoint) === false) {
            throw new RuntimeException('Clip render transaction savepoint failed.');
        }
    }
}
