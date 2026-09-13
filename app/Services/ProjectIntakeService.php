<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JobDispatcher;
use App\Contracts\PrivateStorage;
use App\Contracts\ProjectCreator;
use App\Contracts\SourceArtifactCleanupStore;
use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use App\Media\ProjectReceipt;
use App\Media\UploadValidator;
use App\Media\YoutubeUrlValidator;
use App\Plans\PlanLimitExceeded;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use PDO;

final class ProjectIntakeService implements ProjectCreator
{
    public function __construct(
        private PDO $pdo,
        private ProjectRepository $projects,
        private ProjectSourceRepository $sources,
        private PrivateStorage $storage,
        private JobDispatcher $jobs,
        private UploadValidator $uploads,
        private DirectUrlValidator $urls,
        private ?PlanQuotaService $quotas = null,
        private ?SourceArtifactCleanupStore $sourceCleanups = null,
        private ?YoutubeUrlValidator $youtubeUrls = null
    ) {
        $this->youtubeUrls ??= new YoutubeUrlValidator();
    }

    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
    {
        $automaticExports = $this->automaticExports($input);
        $name = $this->validatedName($input);
        $ingestKey = $this->ingestKey($userId, $input);
        $upload = $this->uploads->validate($file);
        $this->assertUploadPreflight($userId, $upload->sizeBytes());
        $objectKey = 'users/' . $userId . '/uploads/' . bin2hex(random_bytes(16)) . '.' . $upload->extension();
        if ($this->sourceCleanups !== null && !$this->sourceCleanups->reserve($objectKey)) {
            throw new \RuntimeException('Source reservation is unavailable.');
        }
        try {
            $object = $this->storage->putUploaded($upload->temporaryPath(), $objectKey);
            if ($object->objectKey() !== $objectKey) {
                throw new \RuntimeException('Storage returned an unexpected source key.');
            }
            $this->pdo->beginTransaction();
            $this->sourceCleanups?->lockForPublication($objectKey);
            if ($this->quotas !== null) {
                $this->quotas->lockForAdmission($userId);
            }
            $project = $this->projects->createOrFindForIntake($userId, $name, $ingestKey, $upload->originalName());
            if ($project['created']) {
                if ($this->quotas !== null) {
                    $this->quotas->assertUploadBytesAllowed($userId, $object->sizeBytes());
                    $this->quotas->assertAdditionalStorageAvailable($userId, $object->sizeBytes());
                }
                $this->sources->createUpload($project['id'], $upload, $object);
                if ($automaticExports) {
                    $this->projects->requestAutomaticExports($project['id'], $userId);
                }
                $this->jobs->dispatch('probe_source', $project['id'], [], hash('sha256', 'probe_source:' . $project['id']));
                $this->sourceCleanups?->release($objectKey);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (\Throwable) { /* Preserve the original failure and durable reservation. */ }
            }
            $this->discardUpload($objectKey);
            throw $exception;
        }
        if (!$project['created']) {
            $this->discardUpload($objectKey);
        }
        return new ProjectReceipt($project['id'], $project['status'], $project['created']);
    }

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt
    {
        $automaticExports = $this->automaticExports($input);
        $name = $this->validatedName($input);
        $ingestKey = $this->ingestKey($userId, $input);
        $rawUrl = is_string($input['source_url'] ?? null) ? $input['source_url'] : '';
        if ($this->youtubeUrls->recognizes($rawUrl)) {
            if (($input['youtube_rights_confirmed'] ?? null) !== '1') {
                throw MediaValidationException::withCode('youtube_rights_required');
            }
            $url = $this->youtubeUrls->validate($rawUrl);
        } else {
            $url = $this->urls->validate($rawUrl);
        }
        try {
            $this->pdo->beginTransaction();
            if ($this->quotas !== null) {
                $this->quotas->lockForAdmission($userId);
            }
            $project = $this->projects->createOrFindForIntake($userId, $name, $ingestKey, null);
            if ($project['created']) {
                if ($this->quotas !== null) {
                    $this->quotas->assertAdditionalStorageAvailable($userId, 1);
                }
                $this->sources->createDirectUrl($project['id'], $url);
                if ($automaticExports) {
                    $this->projects->requestAutomaticExports($project['id'], $userId);
                }
                $this->jobs->dispatch('fetch_and_probe', $project['id'], [], hash('sha256', 'fetch_and_probe:' . $project['id']));
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return new ProjectReceipt($project['id'], $project['status'], $project['created']);
    }

    private function automaticExports(array $input): bool
    {
        $value = $input['auto_render_requested'] ?? '0';
        if (!in_array($value, ['0', '1'], true)) {
            throw MediaValidationException::withCode('invalid_auto_render_choice');
        }

        return $value === '1';
    }

    private function discardUpload(string $objectKey): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                return; // An unconfirmed rollback must not delete potentially published bytes.
            }
            if ($this->sourceCleanups !== null) {
                $this->sourceCleanups->discard($objectKey,fn () => $this->storage->delete($objectKey));
            } else {
                $this->storage->delete($objectKey);
            }
        } catch (\Throwable) {
            // Leave the committed reservation for maintenance without masking intake errors or replay receipts.
        }
    }

    private function assertUploadPreflight(int $userId, int $bytes): void
    {
        if ($this->quotas === null) {
            return;
        }
        $snapshot = $this->quotas->snapshotForUser($userId);
        $limit = (int) $snapshot['plan']['features']['limits']['max_upload_bytes'];
        if ($bytes > $limit) {
            throw new PlanLimitExceeded('upload_limit_exceeded', $limit, 0, $bytes);
        }
    }

    private function validatedName(array $input): string
    {
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 255) {
            throw MediaValidationException::withCode('invalid_project_name');
        }
        return $name;
    }

    private function ingestKey(int $userId, array $input): string
    {
        $key = trim(is_string($input['idempotency_key'] ?? null) ? $input['idempotency_key'] : '');
        if ($key === '' || strlen($key) > 255) {
            throw MediaValidationException::withCode('invalid_idempotency_key');
        }
        return hash('sha256', $userId . ':' . $key);
    }
}
