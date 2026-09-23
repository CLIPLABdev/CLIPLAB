<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Ai\AiAnalysisReceipt;
use App\Ai\AiAnalysisResult;
use App\Gemini\GeminiFile;
use App\Queue\ProcessingErrorCatalog;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class AiAnalysisRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createOrFind(int $projectId, string $promptVersion, string $model): AiAnalysisReceipt
    {
        $this->assertIdentity($projectId, $promptVersion, $model);
        $statement = $this->pdo->prepare(
            "INSERT INTO ai_analyses (project_id, prompt_version, model, status)
             VALUES (:project_id, :prompt_version, :model, 'queued')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $statement->execute([
            'project_id' => $projectId,
            'prompt_version' => $promptVersion,
            'model' => $model,
        ]);
        $created = $statement->rowCount() === 1;
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('AI analysis could not be resolved.');
        }
        $row = $this->findForProject($id, $projectId);
        if ($row === null || !hash_equals($promptVersion, (string) $row['prompt_version'])
            || !hash_equals($model, (string) $row['model'])
        ) {
            throw new RuntimeException('AI analysis idempotency conflict.');
        }

        return new AiAnalysisReceipt($id, null, (string) $row['status'], $created);
    }

    /** @return array<string, mixed>|null */
    public function findForProject(int $analysisId, int $projectId): ?array
    {
        return $this->find($analysisId, $projectId, false);
    }

    /** @return array<string, mixed>|null */
    public function findLockedForProject(int $analysisId, int $projectId): ?array
    {
        return $this->find($analysisId, $projectId, true);
    }

    public function markUploading(int $analysisId): void
    {
        $this->assertPositive($analysisId);
        $statement = $this->pdo->prepare(
            "UPDATE ai_analyses SET status = 'uploading', error_code = NULL, error_message = NULL
             WHERE id = :id AND status IN ('queued', 'uploading') AND gemini_file_name IS NULL"
        );
        $statement->execute(['id' => $analysisId]);
        if ($statement->rowCount() === 0 && !$this->exists($analysisId)) {
            throw new RuntimeException('AI analysis was not found.');
        }
    }

    public function checkpointFile(int $analysisId, GeminiFile $file): void
    {
        $this->assertPositive($analysisId);
        $status = $file->state() === 'ACTIVE' ? 'generating' : 'waiting_file';
        $statement = $this->pdo->prepare(
            'UPDATE ai_analyses
             SET gemini_file_name = :file_name, gemini_file_uri = :file_uri,
                 gemini_file_mime = :file_mime, gemini_file_state = :file_state,
                 status = :status, error_code = NULL, error_message = NULL
             WHERE id = :id AND status NOT IN (\'completed\', \'failed\')'
        );
        $statement->execute([
            'id' => $analysisId,
            'file_name' => $file->name(),
            'file_uri' => $file->uri(),
            'file_mime' => $file->mimeType(),
            'file_state' => $file->state(),
            'status' => $status,
        ]);
        if ($statement->rowCount() === 0 && !$this->exists($analysisId)) {
            throw new RuntimeException('AI analysis was not found.');
        }
    }

    public function incrementValidationAttempts(int $analysisId): int
    {
        $this->assertPositive($analysisId);
        $statement = $this->pdo->prepare(
            'UPDATE ai_analyses SET validation_attempts = validation_attempts + 1
             WHERE id = :id AND validation_attempts < 255 AND status NOT IN (\'completed\', \'failed\')'
        );
        $statement->execute(['id' => $analysisId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('AI validation attempt could not be recorded.');
        }
        $select = $this->pdo->prepare('SELECT validation_attempts FROM ai_analyses WHERE id = :id');
        $select->execute(['id' => $analysisId]);

        return (int) $select->fetchColumn();
    }

    public function storeValidatedResult(int $analysisId, string $json, AiAnalysisResult $result): void
    {
        $this->assertPositive($analysisId);
        $statement = $this->pdo->prepare(
            "UPDATE ai_analyses
             SET status = 'validating', validated_response_json = :json, video_summary = :summary,
                 error_code = NULL, error_message = NULL
             WHERE id = :id AND status NOT IN ('completed', 'failed')"
        );
        $statement->execute([
            'id' => $analysisId,
            'json' => $json,
            'summary' => $result->videoSummary(),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('AI analysis result could not be stored.');
        }
    }

    public function markFailed(int $analysisId, string $code, string $message): void
    {
        $this->assertPositive($analysisId);
        ProcessingErrorCatalog::assert($code, $message);
        $statement = $this->pdo->prepare(
            "UPDATE ai_analyses
             SET status = 'failed', error_code = :code, error_message = :message
             WHERE id = :id AND status <> 'completed'"
        );
        $statement->execute(['id' => $analysisId, 'code' => $code, 'message' => $message]);
        if ($statement->rowCount() === 0 && !$this->exists($analysisId)) {
            throw new RuntimeException('AI analysis was not found.');
        }
    }

    public function markCompleted(int $analysisId): void
    {
        $this->assertPositive($analysisId);
        $statement = $this->pdo->prepare(
            "UPDATE ai_analyses
             SET status = 'completed', completed_at = COALESCE(completed_at, UTC_TIMESTAMP()),
                 error_code = NULL, error_message = NULL
             WHERE id = :id AND status <> 'failed'"
        );
        $statement->execute(['id' => $analysisId]);
        if ($statement->rowCount() === 0 && !$this->exists($analysisId)) {
            throw new RuntimeException('AI analysis was not found.');
        }
    }

    /** @return array<string, mixed>|null */
    private function find(int $analysisId, int $projectId, bool $locked): ?array
    {
        $this->assertPositive($analysisId);
        $this->assertPositive($projectId);
        $select = $locked
            ? 'SELECT a.id, a.project_id, NULL AS user_id, a.prompt_version, a.model, a.status,
                    a.gemini_file_name, a.gemini_file_uri, a.gemini_file_mime, a.gemini_file_state,
                    a.video_summary, a.validated_response_json, a.validation_attempts,
                    a.error_code, a.error_message, a.provider_request_id, NULL AS duration_seconds
               FROM ai_analyses a'
            : 'SELECT a.id, a.project_id, p.user_id, a.prompt_version, a.model, a.status,
                    a.gemini_file_name, a.gemini_file_uri, a.gemini_file_mime, a.gemini_file_state,
                    a.video_summary, a.validated_response_json, a.validation_attempts,
                    a.error_code, a.error_message, a.provider_request_id, s.duration_seconds
               FROM ai_analyses a
               INNER JOIN projects p ON p.id = a.project_id
               LEFT JOIN project_sources s ON s.project_id = a.project_id AND s.status = \'ready\'';
        $statement = $this->pdo->prepare(
            $select . '
             WHERE a.id = :analysis_id AND a.project_id = :project_id
             LIMIT 1' . ($locked && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute(['analysis_id' => $analysisId, 'project_id' => $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($locked) {
            $duration = $this->pdo->prepare(
                "SELECT duration_seconds FROM project_sources
                 WHERE project_id = :project_id AND status = 'ready' LIMIT 1"
            );
            $duration->execute(['project_id' => $projectId]);
            $value = $duration->fetchColumn();
            $row['duration_seconds'] = $value === false ? null : (int) $value;
        }
        foreach (['id', 'project_id', 'validation_attempts'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
        $row['duration_seconds'] = $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'];

        return $row;
    }

    private function exists(int $analysisId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM ai_analyses WHERE id = :id');
        $statement->execute(['id' => $analysisId]);

        return $statement->fetchColumn() !== false;
    }

    private function assertIdentity(int $projectId, string $promptVersion, string $model): void
    {
        $this->assertPositive($projectId);
        if (trim($promptVersion) === '' || strlen($promptVersion) > 32
            || trim($model) === '' || strlen($model) > 100
            || preg_match('/[\x00-\x1F\x7F]/', $promptVersion . $model) === 1
        ) {
            throw new InvalidArgumentException('AI analysis identity is invalid.');
        }
    }

    private function assertPositive(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('AI analysis identifier must be positive.');
        }
    }
}
