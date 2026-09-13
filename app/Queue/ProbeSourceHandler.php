<?php

declare(strict_types=1);

namespace App\Queue;

use App\Contracts\AiPipelineScheduler;
use App\Contracts\MediaProcessor;
use App\Exceptions\MediaValidationException;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Process\ProcessExecutionException;
use PDOException;

final class ProbeSourceHandler implements JobHandler
{
    private const PERSISTENCE_CODE = 'processing_persistence_failed';
    private const PERSISTENCE_MESSAGE = 'Não foi possível salvar o processamento agora. Tente novamente.';
    private const INVALID_MEDIA_CODE = 'invalid_media';
    private const INVALID_MEDIA_MESSAGE = 'O vídeo enviado não pôde ser processado.';
    private const WORKER_ERROR_MESSAGE = 'O processamento falhou. Tente novamente.';
    private const PERSISTENCE_DEFER_SECONDS = 15;
    private const PROCESSOR_UNAVAILABLE_DEFER_SECONDS = 300;

    public function __construct(
        private object $projects,
        private object $sources,
        private MediaProcessor $processor,
        private ProcessingEffectGuard $effects,
        private ?AiPipelineScheduler $aiPipeline = null
    ) {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        try {
            if ($this->sourceIsReady($job->projectId())) {
                $readySource = $this->sources->findForProject($job->projectId());
                if (!$readySource instanceof ProjectSource) {
                    return $this->failProject($job, 'source_not_found', 'A origem do vídeo não foi encontrada.');
                }

                return $this->scheduleReadyReplay($job, $readySource);
            }
            $source = $this->sources->findForProject($job->projectId());
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
        if (!$source instanceof ProjectSource) {
            return $this->failProject($job, 'source_not_found', 'A origem do vídeo não foi encontrada.');
        }

        try {
            if (!$this->effects->apply($job, function () use ($job): void {
                $this->projects->updateProcessingState($job->projectId(), 'probing', 70);
            })) {
                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }

        try {
            $metadata = $this->processor->inspect($source);
        } catch (MediaValidationException) {
            return $this->failProject($job, self::INVALID_MEDIA_CODE, self::INVALID_MEDIA_MESSAGE);
        } catch (ProcessExecutionException $exception) {
            return match ($exception->publicCode()) {
                'process_unavailable' => $this->processorUnavailable($job),
                'process_timeout' => $this->transientOrTerminal($job, 'process_timeout', 'A inspeção do vídeo excedeu o tempo permitido.'),
                default => $this->failProject($job, self::INVALID_MEDIA_CODE, self::INVALID_MEDIA_MESSAGE),
            };
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }

        return $this->persistReady($job, $source->id(), $metadata);
    }

    private function persistReady(ClaimedJob $job, int $sourceId, MediaMetadata $metadata): JobOutcome
    {
        try {
            if (!$this->effects->apply($job, function () use ($job, $sourceId, $metadata): void {
                $this->sources->markReady($sourceId, $metadata);
                if ($this->aiPipeline !== null) {
                    $this->aiPipeline->schedule($job->projectId(), $sourceId, $metadata->durationSeconds());
                } else {
                    $this->projects->updateProcessingState($job->projectId(), 'ready', 100);
                }
            })) {
                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }

            return JobOutcome::completed();
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
    }

    private function scheduleReadyReplay(ClaimedJob $job, ProjectSource $source): JobOutcome
    {
        if ($this->aiPipeline === null) {
            return JobOutcome::completed();
        }
        try {
            if (!$this->effects->apply($job, function () use ($job, $source): void {
                $ready = $this->sources->findReadyForAnalysis($source->id(), $job->projectId());
                if (!is_array($ready) || !isset($ready['duration_seconds']) || !is_int($ready['duration_seconds'])) {
                    throw new \RuntimeException('Ready source metadata is unavailable.');
                }
                $this->aiPipeline->schedule($job->projectId(), $source->id(), $ready['duration_seconds']);
            })) {
                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }

            return JobOutcome::completed();
        } catch (PDOException) {
            return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
    }

    private function processorUnavailable(ClaimedJob $job): JobOutcome
    {
        try {
            $this->effects->apply($job, function () use ($job): void {
                $this->projects->updateProcessingState($job->projectId(), 'queued', 70);
            });
        } catch (\Throwable) {
            // Capability absence is non-terminal even when the waiting state cannot be persisted.
        }

        return JobOutcome::deferred(self::PROCESSOR_UNAVAILABLE_DEFER_SECONDS);
    }

    private function transientOrTerminal(ClaimedJob $job, string $code, string $message): JobOutcome
    {
        if ($job->attempts() < $job->maxAttempts()) {
            return JobOutcome::retry($code, $message);
        }

        return $this->failProject($job, $code, $message);
    }

    private function persistenceFailure(ClaimedJob $job): JobOutcome
    {
        if ($job->attempts() < $job->maxAttempts()) {
            return JobOutcome::retry(self::PERSISTENCE_CODE, self::PERSISTENCE_MESSAGE);
        }

        return $this->failProject($job, self::PERSISTENCE_CODE, self::PERSISTENCE_MESSAGE);
    }

    private function failProject(ClaimedJob $job, string $code, string $message): JobOutcome
    {
        try {
            if (!$this->effects->apply($job, function () use ($job, $code, $message): void {
                $this->sources->markFailedForProject($job->projectId());
                $this->projects->updateProcessingState($job->projectId(), 'failed', 100, $code, $message);
            })) {
                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }

            return JobOutcome::failed($code, $message);
        } catch (\Throwable) {
            if ($job->attempts() < $job->maxAttempts()) {
                return JobOutcome::retry(self::PERSISTENCE_CODE, self::PERSISTENCE_MESSAGE);
            }

            return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
        }
    }

    private function sourceIsReady(int $projectId): bool
    {
        return method_exists($this->sources, 'isReadyForProject') && $this->sources->isReadyForProject($projectId) === true;
    }
}
