<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class ProjectRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{projects: int, processed: int, processed_seconds: int, storage_bytes: int} */
    public function summaryForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS projects,
                    COALESCE(SUM(CASE WHEN status IN ('completed', 'suggestions_ready') THEN 1 ELSE 0 END), 0) AS processed,
                    COALESCE(SUM(processed_duration_seconds), 0) AS processed_seconds,
                    COALESCE(SUM(storage_bytes), 0) AS storage_bytes
             FROM projects
             WHERE user_id = :user_id"
        );
        $statement->execute(['user_id' => $userId]);
        $summary = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'projects' => (int) ($summary['projects'] ?? 0),
            'processed' => (int) ($summary['processed'] ?? 0),
            'processed_seconds' => (int) ($summary['processed_seconds'] ?? 0),
            'storage_bytes' => (int) ($summary['storage_bytes'] ?? 0),
        ];
    }

    /** @return list<array{id: int, name: string, status: string, original_duration_seconds: int, processed_duration_seconds: int, storage_bytes: int, created_at: string}> */
    public function recentForUser(int $userId, int $limit = 6): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, status, original_duration_seconds, processed_duration_seconds, storage_bytes, created_at
             FROM projects
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, min($limit, 6))
        );
        $statement->execute(['user_id' => $userId]);
        $projects = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $project): array => [
            'id' => (int) $project['id'],
            'name' => (string) $project['name'],
            'status' => (string) $project['status'],
            'original_duration_seconds' => (int) $project['original_duration_seconds'],
            'processed_duration_seconds' => (int) $project['processed_duration_seconds'],
            'storage_bytes' => (int) $project['storage_bytes'],
            'created_at' => (string) $project['created_at'],
        ], $projects);
    }

    public function processedSecondsForUserInPeriod(int $userId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(processed_duration_seconds), 0)
             FROM projects
             WHERE user_id = :user_id
               AND created_at >= :starts_at
               AND created_at < :ends_at'
        );
        $statement->execute([
            'user_id' => $userId,
            'starts_at' => $startsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        return (int) $statement->fetchColumn();
    }

    public function synchronizeRenderState(int $projectId): void
    {
        if ($projectId < 1) {
            throw new \InvalidArgumentException('Project identifier must be positive.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE projects p
             SET status = CASE
                    WHEN EXISTS (SELECT 1 FROM clips active WHERE active.project_id = p.id AND active.status IN ('queued', 'rendering') AND active.ai_analysis_id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = p.id)) THEN 'rendering'
                    WHEN EXISTS (SELECT 1 FROM clips completed WHERE completed.project_id = p.id AND completed.status = 'completed' AND completed.ai_analysis_id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = p.id)) THEN 'completed'
                    ELSE 'suggestions_ready'
                 END,
                 progress = CASE
                    WHEN EXISTS (SELECT 1 FROM clips active WHERE active.project_id = p.id AND active.status IN ('queued', 'rendering') AND active.ai_analysis_id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = p.id)) THEN 96
                    WHEN EXISTS (SELECT 1 FROM clips completed WHERE completed.project_id = p.id AND completed.status = 'completed' AND completed.ai_analysis_id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = p.id)) THEN 100
                    ELSE 92
                 END
             WHERE p.id = :project_id"
        );
        $statement->execute(['project_id' => $projectId]);
    }
    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId, int $limit = 24): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.status, p.progress, p.created_at, p.error_code,
                    s.source_type, s.duration_seconds, s.size_bytes,
                    aj.status AS analysis_job_status, aj.last_error_code AS analysis_job_error_code,
                    aj.attempts AS analysis_job_attempts, aj.max_attempts AS analysis_job_max_attempts,
                    aj.available_at AS analysis_job_available_at
             FROM projects p
             LEFT JOIN project_sources s ON s.project_id = p.id
             LEFT JOIN ai_analyses a ON a.id = (
                 SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis
                 WHERE current_analysis.project_id = p.id
             )
             LEFT JOIN processing_jobs aj ON aj.id = (
                 SELECT MAX(current_job.id) FROM processing_jobs current_job
                 WHERE current_job.project_id = p.id
                   AND current_job.type = \'analyze_video\' AND current_job.queue_name = \'media\'
                   AND JSON_EXTRACT(current_job.payload_json, \'$.analysis_id\') = a.id
             )
             WHERE p.user_id = :user_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . max(1, min($limit, 24))
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'progress' => (int) $row['progress'],
            'created_at' => (string) $row['created_at'],
            'error_code' => is_string($row['error_code'] ?? null) ? $row['error_code'] : null,
            'source_type' => (string) ($row['source_type'] ?? 'upload'),
            'duration_seconds' => $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'analysis_job_status' => $row['analysis_job_status'] ?? null,
            'analysis_job_error_code' => $row['analysis_job_error_code'] ?? null,
            'analysis_job_attempts' => $row['analysis_job_attempts'] ?? null,
            'analysis_job_max_attempts' => $row['analysis_job_max_attempts'] ?? null,
            'analysis_job_available_at' => $row['analysis_job_available_at'] ?? null,
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function statusForOwnedProject(int $projectId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.status, p.progress, p.error_code, p.updated_at,
                    s.duration_seconds, s.width, s.height, s.video_codec, s.audio_codec,
                    s.has_audio, s.size_bytes, s.original_name,
                    a.status AS analysis_status,
                    aj.status AS analysis_job_status, aj.last_error_code AS analysis_job_error_code,
                    aj.attempts AS analysis_job_attempts, aj.max_attempts AS analysis_job_max_attempts,
                    aj.available_at AS analysis_job_available_at,
                    COALESCE((SELECT COUNT(*) FROM clips c WHERE c.ai_analysis_id = a.id), 0) AS suggestions_count
             FROM projects p
             LEFT JOIN project_sources s ON s.project_id = p.id
             LEFT JOIN ai_analyses a ON a.id = (
                 SELECT MAX(current_analysis.id)
                 FROM ai_analyses current_analysis
                 WHERE current_analysis.project_id = p.id
             )
             LEFT JOIN processing_jobs aj ON aj.id = (
                 SELECT MAX(current_job.id) FROM processing_jobs current_job
                 WHERE current_job.project_id = p.id
                   AND current_job.type = \'analyze_video\' AND current_job.queue_name = \'media\'
                   AND JSON_EXTRACT(current_job.payload_json, \'$.analysis_id\') = a.id
             )
             WHERE p.id = :project_id AND p.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function detailForOwnedProject(int $projectId, int $userId): ?array
    {
        if ($projectId < 1 || $userId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.status, p.progress, p.error_code, p.updated_at,
                    a.status AS analysis_status, a.video_summary,
                    aj.status AS analysis_job_status, aj.last_error_code AS analysis_job_error_code,
                    aj.attempts AS analysis_job_attempts, aj.max_attempts AS analysis_job_max_attempts,
                    aj.available_at AS analysis_job_available_at
             FROM projects p
             LEFT JOIN ai_analyses a ON a.id = (
                 SELECT MAX(current_analysis.id)
                 FROM ai_analyses current_analysis
                 WHERE current_analysis.project_id = p.id
             )
             LEFT JOIN processing_jobs aj ON aj.id = (
                 SELECT MAX(current_job.id) FROM processing_jobs current_job
                 WHERE current_job.project_id = p.id
                   AND current_job.type = \'analyze_video\' AND current_job.queue_name = \'media\'
                   AND JSON_EXTRACT(current_job.payload_json, \'$.analysis_id\') = a.id
             )
             WHERE p.id = :project_id AND p.user_id = :user_id
             LIMIT 1'
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'progress' => (int) $row['progress'],
            'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
            'updated_at' => (string) $row['updated_at'],
            'analysis_status' => $row['analysis_status'] === null ? null : (string) $row['analysis_status'],
            'video_summary' => $row['video_summary'] === null ? null : (string) $row['video_summary'],
            'analysis_job_status' => $row['analysis_job_status'] ?? null,
            'analysis_job_error_code' => $row['analysis_job_error_code'] ?? null,
            'analysis_job_attempts' => $row['analysis_job_attempts'] ?? null,
            'analysis_job_max_attempts' => $row['analysis_job_max_attempts'] ?? null,
            'analysis_job_available_at' => $row['analysis_job_available_at'] ?? null,
        ];
    }

    /** @return array{id: int, status: string, created: bool} */
    public function createOrFindForIntake(int $userId, string $name, string $ingestKey, ?string $sourceFilename): array
    {
        $statement = $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, source_filename, status, progress, storage_bytes) VALUES (:user_id, :ingest_key, :name, :source_filename, 'queued', 0, 0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = status");
        $statement->execute(['user_id' => $userId, 'ingest_key' => $ingestKey, 'name' => $name, 'source_filename' => $sourceFilename]);
        $id = (int) $this->pdo->lastInsertId();
        $current = $this->findByUserAndIngestKey($userId, $ingestKey);
        if ($id < 1 || $current === null) { throw new \RuntimeException('Project idempotency lookup failed.'); }
        return ['id' => $id, 'status' => $current['status'], 'created' => $statement->rowCount() === 1];
    }
    /** @return array{id: int, status: string}|null */
    public function findByUserAndIngestKey(int $userId, string $ingestKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, status FROM projects WHERE user_id = :user_id AND ingest_key = :ingest_key');
        $statement->execute(['user_id' => $userId, 'ingest_key' => $ingestKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['id' => (int) $row['id'], 'status' => (string) $row['status']] : null;
    }

    public function requestAutomaticExports(int $projectId, int $userId): void
    {
        if (!$this->pdo->inTransaction() || $projectId < 1 || $userId < 1) {
            throw new \LogicException('Automatic export admission requires an owned project transaction.');
        }
        $statement = $this->pdo->prepare('UPDATE projects SET auto_render_requested = 1 WHERE id = ? AND user_id = ?');
        $statement->execute([$projectId, $userId]);
    }

    public function updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void
    {
        if ($projectId < 1 || !in_array($status, ['receiving', 'queued', 'fetching', 'probing', 'ready', 'failed'], true) || $progress < 0 || $progress > 100) {
            throw new \InvalidArgumentException('Project processing state is invalid.');
        }
        if (($errorCode === null) !== ($publicMessage === null)
            || ($errorCode !== null && preg_match('/^[a-z0-9_]{1,64}$/', $errorCode) !== 1)
            || ($publicMessage !== null && (trim($publicMessage) === '' || mb_strlen($publicMessage) > 255))) {
            throw new \InvalidArgumentException('Project processing error is invalid.');
        }
        if ($status === 'ready' && $errorCode !== null) {
            throw new \InvalidArgumentException('Ready projects cannot have a processing error.');
        }
        if ($status === 'failed' && $errorCode === null) {
            throw new \InvalidArgumentException('Failed projects require a public processing error.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE projects
             SET status = :status, progress = :progress, error_code = :error_code, error_message = :error_message
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $projectId,
            'status' => $status,
            'progress' => $progress,
            'error_code' => $errorCode,
            'error_message' => $publicMessage,
        ]);
    }

    public function ownerId(int $projectId): ?int
    {
        if ($projectId < 1) {
            throw new \InvalidArgumentException('Project identifier must be positive.');
        }
        $statement = $this->pdo->prepare('SELECT user_id FROM projects WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $projectId]);
        $ownerId = $statement->fetchColumn();

        return $ownerId === false ? null : (int) $ownerId;
    }

    public function advanceProcessingState(
        int $projectId,
        string $status,
        ?string $errorCode = null,
        ?string $publicMessage = null
    ): void {
        $progressByState = [
            'ai_queued' => 75,
            'awaiting_credits' => 75,
            'uploading_ai' => 80,
            'waiting_ai_file' => 82,
            'analyzing' => 88,
            'identifying_clips' => 95,
            'suggestions_ready' => 100,
            'failed' => 100,
        ];
        if ($projectId < 1 || !isset($progressByState[$status])) {
            throw new \InvalidArgumentException('AI project processing state is invalid.');
        }
        $requiresError = in_array($status, ['awaiting_credits', 'failed'], true);
        if ($requiresError !== ($errorCode !== null)
            || ($errorCode === null) !== ($publicMessage === null)
            || ($errorCode !== null && preg_match('/^[a-z0-9_]{1,64}$/D', $errorCode) !== 1)
            || ($publicMessage !== null && (trim($publicMessage) === '' || mb_strlen($publicMessage, 'UTF-8') > 255))
        ) {
            throw new \InvalidArgumentException('AI project processing error is invalid.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction && !$this->pdo->beginTransaction()) {
            throw new RuntimeException('Project state transaction could not start.');
        }
        try {
            $lock = $this->pdo->prepare(
                'SELECT status, progress FROM projects WHERE id = :id LIMIT 1'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
            );
            $lock->execute(['id' => $projectId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                throw new RuntimeException('Project was not found.');
            }
            $currentStatus = (string) $current['status'];
            if (in_array($currentStatus, ['failed', 'suggestions_ready'], true)) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return;
            }
            $rank = [
                'receiving' => 0,
                'queued' => 0,
                'fetching' => 20,
                'probing' => 70,
                'ready' => 70,
                'ai_queued' => 75,
                'awaiting_credits' => 75,
                'uploading_ai' => 80,
                'waiting_ai_file' => 82,
                'analyzing' => 88,
                'identifying_clips' => 95,
            ];
            if ($status !== 'failed'
                && ($rank[$status] ?? $progressByState[$status]) < ($rank[$currentStatus] ?? (int) $current['progress'])
            ) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return;
            }
            $update = $this->pdo->prepare(
                'UPDATE projects
                 SET status = :status, progress = :progress, error_code = :error_code, error_message = :error_message
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $projectId,
                'status' => $status,
                'progress' => max((int) $current['progress'], $progressByState[$status]),
                'error_code' => $errorCode,
                'error_message' => $publicMessage,
            ]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
