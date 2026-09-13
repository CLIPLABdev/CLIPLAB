<?php

declare(strict_types=1);

namespace App\Queue;

use App\Contracts\AiPipelineScheduler;
use App\Contracts\MediaProcessor;
use App\Contracts\PrivateStorage;
use App\Contracts\SourceArtifactCleanupStore;
use App\Contracts\YoutubeMediaResolver;
use App\Exceptions\MediaValidationException;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Media\ValidatedYoutubeUrl;
use App\Media\YoutubeUrlValidator;
use App\Plans\PlanLimitExceeded;
use PDOException;

final class FetchAndProbeHandler implements JobHandler
{
    private const PERSISTENCE_CODE = 'processing_persistence_failed';
    private const PERSISTENCE_MESSAGE = 'Não foi possível salvar o processamento agora. Tente novamente.';
    private const INVALID_MEDIA_CODE = 'invalid_media';
    private const INVALID_MEDIA_MESSAGE = 'O vídeo enviado não pôde ser processado.';
    private const WORKER_ERROR_MESSAGE = 'O processamento falhou. Tente novamente.';
    private const PERSISTENCE_DEFER_SECONDS = 15;

    public function __construct(
        private object $projects,
        private object $sources,
        private object $downloader,
        private PrivateStorage $storage,
        private MediaProcessor $processor,
        private ProcessingEffectGuard $effects,
        private int $maxDownloadBytes = 524288000,
        private ?AiPipelineScheduler $aiPipeline = null,
        private ?object $quotas = null,
        private ?SourceArtifactCleanupStore $sourceCleanups = null,
        private ?YoutubeMediaResolver $youtubeResolver = null,
        private ?YoutubeUrlValidator $youtubeUrls = null
    ) {
        if ($maxDownloadBytes < 1) {
            throw new \InvalidArgumentException('The download limit must be positive.');
        }
        if ($quotas !== null && (!method_exists($quotas, 'snapshotForUser')
            || !method_exists($quotas, 'assertUploadBytesAllowed')
            || !method_exists($quotas, 'assertAdditionalStorageAvailable'))) {
            throw new \InvalidArgumentException('The quota service contract is invalid.');
        }
        if ($this->youtubeResolver !== null && $this->youtubeUrls === null) {
            $this->youtubeUrls = new YoutubeUrlValidator();
        }
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        try {
            if ($this->sourceIsReady($job->projectId())) {
                if ($this->aiPipeline === null) {
                    return JobOutcome::completed();
                }

                return (new ProbeSourceHandler(
                    $this->projects,
                    $this->sources,
                    $this->processor,
                    $this->effects,
                    $this->aiPipeline
                ))->handle($job);
            }
            $stored = $this->sources->findForProject($job->projectId());
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
        if ($stored instanceof ProjectSource) {
            return (new ProbeSourceHandler($this->projects, $this->sources, $this->processor, $this->effects, $this->aiPipeline))->handle($job);
        }

        try {
            $pending = $this->sources->findDirectUrlForProject($job->projectId());
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
        if (!is_array($pending) || !isset($pending['sourceId'], $pending['url']) || !is_int($pending['sourceId']) || !is_string($pending['url'])) {
            return $this->failProject($job, 'source_not_found', 'A origem do vídeo não foi encontrada.');
        }

        $youtubeUrl = null;
        try {
            if ($this->youtubeUrls !== null && $this->youtubeUrls->recognizes($pending['url'])) {
                $youtubeUrl = $this->youtubeUrls->validate($pending['url']);
                if ($this->youtubeResolver === null || !method_exists($this->downloader, 'downloadYoutube')) {
                    return $this->failProject(
                        $job,
                        'youtube_import_unavailable',
                        ProcessingErrorCatalog::requireMessage('youtube_import_unavailable')
                    );
                }
            } else {
                $this->metadataForUrl($pending['url']);
            }
        } catch (MediaValidationException) {
            return $this->failProject($job, self::INVALID_MEDIA_CODE, self::INVALID_MEDIA_MESSAGE);
        }

        $ownerId = null;
        $downloadLimit = $this->maxDownloadBytes;
        try {
            if ($this->quotas !== null) {
                if (!method_exists($this->projects, 'ownerId')) {
                    throw new \RuntimeException('Project owner lookup is unavailable.');
                }
                $ownerId = $this->projects->ownerId($job->projectId());
                if (!is_int($ownerId) || $ownerId < 1) {
                    throw new \RuntimeException('Project owner is unavailable.');
                }
                $snapshot = $this->quotas->snapshotForUser($ownerId);
                $planLimit = $snapshot['plan']['features']['limits']['max_upload_bytes'] ?? null;
                if (!is_int($planLimit) || $planLimit < 1) {
                    throw new \RuntimeException('Project upload quota is unavailable.');
                }
                $downloadLimit = min($downloadLimit, $planLimit);
            }
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }

        try {
            if (!$this->effects->apply($job, function () use ($job): void {
                $this->projects->updateProcessingState($job->projectId(), 'fetching', 20);
            })) {
                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }

        $objectKey = $this->objectKey($job->projectId(), $pending['url'], $youtubeUrl !== null);
        try {
            if ($this->sourceCleanups !== null && !$this->sourceCleanups->reserve($objectKey, $job)) {
                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }
        } catch (PDOException) {
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
        try {
            if ($youtubeUrl instanceof ValidatedYoutubeUrl) {
                $resolved = $this->youtubeResolver instanceof \App\Contracts\BudgetedYoutubeMediaResolver
                    ? $this->youtubeResolver->resolveWithinLimit($youtubeUrl, $downloadLimit)
                    : $this->youtubeResolver->resolve($youtubeUrl);
                $object = $this->downloader->downloadYoutube($resolved, $this->storage, $objectKey, $downloadLimit);
            } else {
                $object = $this->downloader->downloadStoredUrl($pending['url'], $this->storage, $objectKey, $downloadLimit);
            }
        } catch (MediaValidationException $exception) {
            $this->discardAllocatedKey($objectKey);
            if ($downloadLimit < $this->maxDownloadBytes && $exception->publicCode() === 'media_too_large') {
                return $this->failProject(
                    $job,
                    'upload_limit_exceeded',
                    ProcessingErrorCatalog::requireMessage('upload_limit_exceeded')
                );
            }
            return $this->downloadFailure($job, $exception);
        } catch (PDOException) {
            $this->discardAllocatedKey($objectKey);
            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            $this->discardAllocatedKey($objectKey);
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }
        if (!$object instanceof StoredObject || ($this->sourceCleanups !== null && $object->objectKey() !== $objectKey)) {
            $this->discardAllocatedKey($objectKey);
            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }

        try {
            if (!$this->effects->apply($job, function () use ($pending, $object, $ownerId, $youtubeUrl): void {
                $this->sourceCleanups?->lockForPublication($object->objectKey());
                [$mimeType, $extension] = $youtubeUrl instanceof ValidatedYoutubeUrl
                    ? ['video/mp4', 'mp4']
                    : $this->metadataForUrl($pending['url']);
                if ($this->quotas !== null && $ownerId !== null) {
                    $this->quotas->assertUploadBytesAllowed($ownerId, $object->sizeBytes());
                    $this->quotas->assertAdditionalStorageAvailable($ownerId, $object->sizeBytes());
                }
                $this->sources->markStored($pending['sourceId'], $object, $mimeType, $extension);
                $this->sourceCleanups?->release($object->objectKey());
            })) {
                $this->deleteNewObject($object);

                return JobOutcome::deferred(self::PERSISTENCE_DEFER_SECONDS);
            }
        } catch (PlanLimitExceeded $exception) {
            $this->deleteNewObject($object);
            $message = ProcessingErrorCatalog::message($exception->errorCode()) ?? self::WORKER_ERROR_MESSAGE;

            return $this->failProject($job, $exception->errorCode(), $message);
        } catch (MediaValidationException) {
            $this->deleteNewObject($object);

            return $this->failProject($job, self::INVALID_MEDIA_CODE, self::INVALID_MEDIA_MESSAGE);
        } catch (PDOException) {
            $this->deleteNewObject($object);

            return $this->persistenceFailure($job);
        } catch (\Throwable) {
            $this->deleteNewObject($object);

            return $this->failProject($job, 'worker_error', self::WORKER_ERROR_MESSAGE);
        }

        return (new ProbeSourceHandler($this->projects, $this->sources, $this->processor, $this->effects, $this->aiPipeline))->handle($job);
    }

    private function downloadFailure(ClaimedJob $job, MediaValidationException $exception): JobOutcome
    {
        return match ($exception->publicCode()) {
            'youtube_video_unavailable', 'youtube_response_invalid', 'youtube_metadata_limit',
            'youtube_bot_challenge', 'youtube_age_restricted', 'youtube_region_restricted',
            'youtube_private_video', 'youtube_login_required', 'youtube_format_unavailable', 'media_too_large' => $this->failProject(
                $job,
                $exception->publicCode(),
                ProcessingErrorCatalog::requireMessage($exception->publicCode())
            ),
            'youtube_rate_limited', 'youtube_network_failed' => $this->transientOrTerminal(
                $job, $exception->publicCode(), ProcessingErrorCatalog::requireMessage($exception->publicCode())
            ),
            'youtube_import_unavailable' => $this->failProject(
                $job,
                'youtube_import_unavailable',
                ProcessingErrorCatalog::requireMessage('youtube_import_unavailable')
            ),
            'remote_timeout' => $this->transientOrTerminal($job, 'network_timeout', 'Não foi possível baixar o vídeo agora. Tente novamente.'),
            'remote_download_failed', 'remote_download_unavailable', 'storage_write_failed' => $this->transientOrTerminal($job, 'download_unavailable', 'Não foi possível baixar o vídeo agora. Tente novamente.'),
            default => $this->failProject($job, self::INVALID_MEDIA_CODE, self::INVALID_MEDIA_MESSAGE),
        };
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

    /** @return array{string, string} */
    private function metadataForUrl(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => ['video/mp4', 'mp4'],
            'mov' => ['video/quicktime', 'mov'],
            'webm' => ['video/webm', 'webm'],
            default => throw MediaValidationException::withCode('invalid_media_container'),
        };
    }

    private function objectKey(int $projectId, string $url, bool $youtube = false): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = $youtube ? 'mp4' : strtolower((string) pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));

        return 'imports/' . $projectId . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    private function sourceIsReady(int $projectId): bool
    {
        return method_exists($this->sources, 'isReadyForProject') && $this->sources->isReadyForProject($projectId) === true;
    }

    private function deleteNewObject(StoredObject $object): void
    {
        try {
            if ($this->sourceCleanups !== null) {
                $this->sourceCleanups->discard($object->objectKey(),fn () => $this->storage->delete($object->objectKey()));
            } else {
                $this->storage->delete($object->objectKey());
            }
        } catch (\Throwable) {
            // A cleanup failure is intentionally not allowed to change the public failure class.
        }
    }

    private function discardAllocatedKey(string $objectKey): void
    {
        if ($this->sourceCleanups === null) {
            return;
        }
        try {
            $this->sourceCleanups->discard($objectKey,fn () => $this->storage->delete($objectKey));
        } catch (\Throwable) {
            // A partial download remains covered by the durable reservation for the next maintenance pass.
        }
    }
}
