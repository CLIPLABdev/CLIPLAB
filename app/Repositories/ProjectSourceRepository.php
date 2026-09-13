<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Media\ValidatedRemoteUrl;
use App\Media\ValidatedYoutubeUrl;
use App\Media\ValidatedUpload;
use PDO;

final class ProjectSourceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createUpload(int $projectId, ValidatedUpload $upload, StoredObject $object): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, sha256, status)
             VALUES (:project_id, 'upload', 'local', :object_key, :original_name, :extension, :mime_type, :size_bytes, :sha256, 'stored')"
        );
        $statement->execute([
            'project_id' => $projectId,
            'object_key' => $object->objectKey(),
            'original_name' => $upload->originalName(),
            'extension' => $upload->extension(),
            'mime_type' => $upload->mimeType(),
            'size_bytes' => $object->sizeBytes(),
            'sha256' => $object->sha256(),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createDirectUrl(int $projectId, ValidatedRemoteUrl|ValidatedYoutubeUrl $url): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO project_sources (project_id, source_type, source_url, source_host, status)
             VALUES (:project_id, 'direct_url', :source_url, :source_host, 'pending')"
        );
        $statement->execute(['project_id' => $projectId, 'source_url' => $url->url(), 'source_host' => $url->host()]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findForOwnedProject(int $projectId, int $userId): ?ProjectSource
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.project_id, s.storage_disk, s.object_key, s.mime_type
             FROM project_sources s
             INNER JOIN projects p ON p.id = s.project_id
             WHERE s.project_id = :project_id
               AND p.user_id = :user_id
               AND s.object_key IS NOT NULL
               AND s.mime_type IS NOT NULL'
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return new ProjectSource((int) $row['id'], (int) $row['project_id'], (string) $row['storage_disk'], (string) $row['object_key'], (string) $row['mime_type']);
    }

    public function markStored(int $sourceId, StoredObject $object, string $mimeType, string $extension): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE project_sources
             SET storage_disk = 'local', object_key = :object_key, mime_type = :mime_type, extension = :extension,
                 size_bytes = :size_bytes, sha256 = :sha256, status = 'stored', fetched_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $sourceId,
            'object_key' => $object->objectKey(),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $object->sizeBytes(),
            'sha256' => $object->sha256(),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Project source was not found.');
        }
    }

    public function findForProject(int $projectId): ?ProjectSource
    {
        $statement = $this->pdo->prepare(
            "SELECT id, project_id, storage_disk, object_key, mime_type
             FROM project_sources
             WHERE project_id = :project_id
               AND status IN ('stored', 'ready')
               AND object_key IS NOT NULL
               AND mime_type IS NOT NULL"
        );
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return new ProjectSource((int) $row['id'], (int) $row['project_id'], (string) $row['storage_disk'], (string) $row['object_key'], (string) $row['mime_type']);
    }

    public function isReadyForProject(int $projectId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM project_sources WHERE project_id = :project_id AND status = 'ready' LIMIT 1"
        );
        $statement->execute(['project_id' => $projectId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array{source: ProjectSource, duration_seconds: int}|null */
    public function findReadyForAnalysis(int $sourceId, int $projectId): ?array
    {
        if ($sourceId < 1 || $projectId < 1) {
            throw new \InvalidArgumentException('Project source identifiers must be positive.');
        }
        $statement = $this->pdo->prepare(
            "SELECT id, project_id, storage_disk, object_key, mime_type, duration_seconds
             FROM project_sources
             WHERE id = :source_id AND project_id = :project_id
               AND status = 'ready'
               AND object_key IS NOT NULL AND mime_type IS NOT NULL
               AND duration_seconds BETWEEN 1 AND 86400
             LIMIT 1"
        );
        $statement->execute(['source_id' => $sourceId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'source' => new ProjectSource(
                (int) $row['id'],
                (int) $row['project_id'],
                (string) $row['storage_disk'],
                (string) $row['object_key'],
                (string) $row['mime_type']
            ),
            'duration_seconds' => (int) $row['duration_seconds'],
        ];
    }

    /** @return array{sourceId: int, url: string}|null */
    public function findDirectUrlForProject(int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, source_url
             FROM project_sources
             WHERE project_id = :project_id
               AND source_type = 'direct_url'
               AND status = 'pending'
               AND object_key IS NULL
               AND source_url IS NOT NULL"
        );
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['source_url']) || $row['source_url'] === '') {
            return null;
        }
        return ['sourceId' => (int) $row['id'], 'url' => $row['source_url']];
    }

    /** @return array<string,int|string>|null */
    public function findReadyIdentityForOwnedProject(int $projectId, int $userId, bool $forUpdate = false): ?array
    {
        if ($projectId < 1 || $userId < 1) return null;
        if ($forUpdate && !$this->pdo->inTransaction()) throw new \LogicException('Source locking requires a transaction.');
        $suffix = $forUpdate && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $this->pdo->prepare(
            "SELECT s.id,s.project_id,p.user_id AS owner_id,s.storage_disk,s.object_key,s.mime_type,
                    s.sha256,s.size_bytes,s.status,s.duration_seconds
             FROM project_sources s INNER JOIN projects p ON p.id=s.project_id
             WHERE s.project_id=:project AND p.user_id=:owner AND s.status='ready'
               AND s.object_key IS NOT NULL AND s.mime_type IS NOT NULL
               AND s.duration_seconds BETWEEN 1 AND 86400
             ORDER BY s.id LIMIT 2" . $suffix
        );
        $query->execute(['project'=>$projectId,'owner'=>$userId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        if (!is_string($row['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256']) !== 1
            || (int)$row['size_bytes'] < 1 || trim((string)$row['storage_disk']) === ''
            || trim((string)$row['object_key']) === '' || trim((string)$row['mime_type']) === '') return null;
        return [
            'id'=>(int)$row['id'],'project_id'=>(int)$row['project_id'],'owner_id'=>(int)$row['owner_id'],
            'storage_disk'=>(string)$row['storage_disk'],'object_key'=>(string)$row['object_key'],
            'mime_type'=>(string)$row['mime_type'],'sha256'=>$row['sha256'],'size_bytes'=>(int)$row['size_bytes'],
            'status'=>(string)$row['status'],'duration_seconds'=>(int)$row['duration_seconds'],
        ];
    }

    public function markReady(int $sourceId, MediaMetadata $metadata): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE project_sources
             SET duration_seconds = :duration_seconds, width = :width, height = :height,
                 video_codec = :video_codec, audio_codec = :audio_codec, has_audio = :has_audio,
                 status = 'ready'
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $sourceId,
            'duration_seconds' => $metadata->durationSeconds(),
            'width' => $metadata->width(),
            'height' => $metadata->height(),
            'video_codec' => $metadata->videoCodec(),
            'audio_codec' => $metadata->audioCodec(),
            'has_audio' => $metadata->hasAudio() ? 1 : 0,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Project source was not found.');
        }
    }

    public function markFailedForProject(int $projectId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE project_sources SET status = 'failed' WHERE project_id = :project_id AND status <> 'failed'"
        );
        $statement->execute(['project_id' => $projectId]);
    }

    public function markFailed(int $sourceId): void
    {
        $statement = $this->pdo->prepare("UPDATE project_sources SET status = 'failed' WHERE id = :id");
        $statement->execute(['id' => $sourceId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Project source was not found.');
        }
    }
}
