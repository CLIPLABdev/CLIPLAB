<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Ai\AiAnalysisResult;
use App\Ai\AiClipSuggestion;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use PDO;
use RuntimeException;

final class ClipRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<int> */
    public function materialize(int $analysisId, int $projectId, AiAnalysisResult $result): array
    {
        if ($analysisId < 1 || $projectId < 1) {
            throw new \InvalidArgumentException('Clip parent identifiers must be positive.');
        }
        $ids = [];
        foreach ($result->clips() as $clip) {
            $existing = $this->findByIndex($analysisId, $clip->index(), true);
            if ($existing !== null) {
                $this->assertSameContent($existing, $projectId, $clip);
                $ids[] = (int) $existing['id'];
                continue;
            }
            $statement = $this->pdo->prepare(
                "INSERT INTO clips
                    (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                     duration_seconds, viral_score, hook, reason, category, status)
                 VALUES
                    (:project_id, :analysis_id, :suggestion_index, :title, :start_time, :end_time,
                     :duration_seconds, :viral_score, :hook, :reason, :category, 'suggested')"
            );
            $statement->execute($this->parameters($analysisId, $projectId, $clip));
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        $indices = $this->pdo->prepare(
            'SELECT suggestion_index FROM clips WHERE ai_analysis_id = :analysis_id AND suggestion_index IS NOT NULL ORDER BY suggestion_index ASC'
            . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $indices->execute(['analysis_id' => $analysisId]);
        $actual = array_map('intval', $indices->fetchAll(PDO::FETCH_COLUMN));
        if ($actual !== range(0, count($result->clips()) - 1)) {
            throw new RuntimeException('Stored clips conflict with the validated suggestion set.');
        }

        return $ids;
    }

    /** @return list<array{id:mixed,title:mixed,start_time:mixed,end_time:mixed,render_start_time:mixed,render_end_time:mixed,duration_seconds:mixed,viral_score:mixed,hook:mixed,reason:mixed,category:mixed,status:mixed,output_aspect_ratio:mixed,reframe_mode:mixed}> */
    public function suggestionsForOwnedProject(int $projectId, int $userId): array
    {
        if ($projectId < 1 || $userId < 1) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.title, c.start_time, c.end_time, c.render_start_time, c.render_end_time,
                    c.duration_seconds, c.viral_score, c.hook, c.reason, c.category, c.status, c.render_error_code,
                    COALESCE(rp.aspect_ratio, \'original\') AS output_aspect_ratio,
                    COALESCE(rp.reframe_mode, \'original\') AS reframe_mode
             FROM clips c
             INNER JOIN projects p ON p.id = c.project_id
             INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
             LEFT JOIN clip_render_profiles rp
               ON rp.clip_id = c.id AND rp.render_revision = c.render_revision
             WHERE c.project_id = :project_id AND p.user_id = :user_id
               AND a.id = (
                   SELECT MAX(current_analysis.id)
                   FROM ai_analyses current_analysis
                   WHERE current_analysis.project_id = c.project_id
               )
             ORDER BY c.suggestion_index IS NULL ASC, c.suggestion_index ASC, c.id ASC'
        );
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{id:int,project_id:int,source_id:int,status:string,start_time:float,end_time:float,source_duration_seconds:int,has_audio:bool,render_start_time:?float,render_end_time:?float,render_revision:int}|null */
    public function findForRenderRequest(int $clipId, int $userId, bool $forUpdate = false): ?array
    {
        if ($clipId < 1 || $userId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.project_id, s.id AS source_id, c.status, c.start_time, c.end_time,
                    s.duration_seconds AS source_duration_seconds, s.has_audio, c.render_start_time, c.render_end_time, c.render_revision
             FROM clips c
             INNER JOIN projects p ON p.id = c.project_id AND p.user_id = :user_id
             INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
             INNER JOIN project_sources s ON s.project_id = c.project_id
             WHERE c.id = :clip_id
               AND a.id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = c.project_id)
               AND s.status = \'ready\' AND s.duration_seconds BETWEEN 1 AND 86400
               AND s.object_key IS NOT NULL AND s.mime_type IS NOT NULL
             LIMIT 1' . ($forUpdate && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute(['clip_id' => $clipId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'], 'project_id' => (int) $row['project_id'], 'source_id' => (int) $row['source_id'],
            'status' => (string) $row['status'], 'start_time' => (float) $row['start_time'], 'end_time' => (float) $row['end_time'],
            'source_duration_seconds' => (int) $row['source_duration_seconds'],
            'has_audio' => (bool) $row['has_audio'],
            'render_start_time' => $row['render_start_time'] === null ? null : (float) $row['render_start_time'],
            'render_end_time' => $row['render_end_time'] === null ? null : (float) $row['render_end_time'],
            'render_revision' => (int) $row['render_revision'],
        ];
    }

    public function queueRender(int $clipId, float $start, float $end, int $revision): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE clips SET status = 'queued', render_start_time = :start_time, render_end_time = :end_time,
                    render_revision = :revision, render_error_code = NULL, approved_at = COALESCE(approved_at, UTC_TIMESTAMP()),
                    render_requested_at = UTC_TIMESTAMP()
             WHERE id = :clip_id"
        );
        $statement->execute(['clip_id' => $clipId, 'start_time' => $this->decimal($start), 'end_time' => $this->decimal($end), 'revision' => $revision]);
    }

    /** @return array{id:int,project_id:int,status:string,render_revision:int,render_start_time:float,render_end_time:float,source:ProjectSource}|null */
    public function findForRenderJob(int $clipId, int $revision): ?array
    {
        if ($clipId < 1 || $revision < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT c.id, c.project_id, c.status, c.render_revision, c.render_start_time, c.render_end_time,
                    s.id AS source_id, s.storage_disk, s.object_key, s.mime_type, s.width, s.height
             FROM clips c
             INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
             INNER JOIN project_sources s ON s.project_id = c.project_id
             WHERE c.id = :clip_id AND c.render_revision = :revision AND c.status IN ('queued', 'rendering')
               AND a.id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = c.project_id)
               AND s.status = 'ready' AND s.object_key IS NOT NULL AND s.mime_type IS NOT NULL
             LIMIT 1"
        );
        $statement->execute(['clip_id' => $clipId, 'revision' => $revision]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['render_start_time'] === null || $row['render_end_time'] === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'], 'project_id' => (int) $row['project_id'], 'status' => (string) $row['status'],
            'render_revision' => (int) $row['render_revision'], 'render_start_time' => (float) $row['render_start_time'],
            'render_end_time' => (float) $row['render_end_time'],
            'source' => new ProjectSource(
                (int) $row['source_id'],
                (int) $row['project_id'],
                (string) $row['storage_disk'],
                (string) $row['object_key'],
                (string) $row['mime_type'],
                $row['width'] === null ? null : (int) $row['width'],
                $row['height'] === null ? null : (int) $row['height']
            ),
        ];
    }

    public function markRendering(int $clipId, int $revision): void
    {
        $this->updateRenderState($clipId, $revision, "status = 'rendering', render_error_code = NULL", "status IN ('queued', 'rendering')");
    }

    public function markRenderQueued(int $clipId, int $revision): void
    {
        $this->updateRenderState($clipId, $revision, "status = 'queued'", "status IN ('queued', 'rendering')");
    }

    public function markRenderFailed(int $clipId, int $revision, string $errorCode): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE clips SET status = 'failed', render_error_code = :error_code
             WHERE id = :clip_id AND render_revision = :revision AND status IN ('queued', 'rendering')"
        );
        $statement->execute(['clip_id' => $clipId, 'revision' => $revision, 'error_code' => $errorCode]);
    }

    public function completeRender(int $clipId, int $revision, StoredObject $video, StoredObject $thumbnail): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE clips SET status = 'completed', output_file = :output_file, output_size_bytes = :output_size_bytes,
                    thumbnail = :thumbnail, thumbnail_size_bytes = :thumbnail_size_bytes, render_error_code = NULL,
                    rendered_at = COALESCE(rendered_at, UTC_TIMESTAMP())
             WHERE id = :clip_id AND render_revision = :revision AND status = 'rendering'"
        );
        $statement->execute([
            'clip_id' => $clipId, 'revision' => $revision, 'output_file' => $video->objectKey(),
            'output_size_bytes' => $video->sizeBytes(), 'thumbnail' => $thumbnail->objectKey(), 'thumbnail_size_bytes' => $thumbnail->sizeBytes(),
        ]);
    }

    /** @return array{id:int,project_id:int,status:string,render_start_time:?float,render_end_time:?float,render_error_code:?string,updated_at:string}|null */
    public function statusForOwnedClip(int $clipId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.project_id, c.status, c.render_start_time, c.render_end_time,
                    c.render_error_code, c.updated_at, rp.aspect_ratio AS output_aspect_ratio,
                    rp.reframe_mode
             FROM clips c
             INNER JOIN projects p ON p.id = c.project_id
             INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
             LEFT JOIN clip_render_profiles rp
               ON rp.clip_id = c.id AND rp.render_revision = c.render_revision
             WHERE c.id = :clip_id AND p.user_id = :user_id
               AND a.id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = c.project_id)
             LIMIT 1'
        );
        $statement->execute(['clip_id' => $clipId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'], 'project_id' => (int) $row['project_id'], 'status' => (string) $row['status'],
            'render_start_time' => $row['render_start_time'] === null ? null : (float) $row['render_start_time'],
            'render_end_time' => $row['render_end_time'] === null ? null : (float) $row['render_end_time'],
            'render_error_code' => $row['render_error_code'] === null ? null : (string) $row['render_error_code'],
            'output_aspect_ratio' => $row['output_aspect_ratio'] === null ? null : (string) $row['output_aspect_ratio'],
            'reframe_mode' => $row['reframe_mode'] === null ? null : (string) $row['reframe_mode'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array{object_key:string,size_bytes:int,mime_type:string}|null */
    public function artifactForOwnedClip(int $clipId, int $userId, string $kind): ?array
    {
        if (!in_array($kind, ['video', 'thumbnail'], true)) {
            return null;
        }
        $column = $kind === 'video' ? 'output_file' : 'thumbnail';
        $sizeColumn = $kind === 'video' ? 'output_size_bytes' : 'thumbnail_size_bytes';
        $statement = $this->pdo->prepare(
            "SELECT c.{$column} AS object_key, c.{$sizeColumn} AS size_bytes
             FROM clips c
             INNER JOIN projects p ON p.id = c.project_id
             INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
             WHERE c.id = :clip_id AND p.user_id = :user_id AND c.status = 'completed'
               AND a.id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = c.project_id)
               AND c.{$column} IS NOT NULL AND c.{$sizeColumn} IS NOT NULL LIMIT 1"
        );
        $statement->execute(['clip_id' => $clipId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return ['object_key' => (string) $row['object_key'], 'size_bytes' => (int) $row['size_bytes'], 'mime_type' => $kind === 'video' ? 'video/mp4' : 'image/jpeg'];
    }

    /** @return array{storage_disk:string,object_key:string,size_bytes:int,mime_type:string}|null */
    public function sourceForOwnedPreview(int $clipId, int $userId): ?array
    {
        if ($clipId < 1 || $userId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT s.storage_disk, s.object_key, s.size_bytes, s.mime_type
             FROM clips c
             INNER JOIN projects p ON p.id = c.project_id AND p.user_id = :user_id
             INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
             INNER JOIN project_sources s ON s.project_id = c.project_id
             WHERE c.id = :clip_id
               AND a.id = (SELECT MAX(current_analysis.id) FROM ai_analyses current_analysis WHERE current_analysis.project_id = c.project_id)
               AND s.status = 'ready' AND s.storage_disk IS NOT NULL
               AND s.object_key IS NOT NULL AND s.object_key <> ''
               AND s.size_bytes > 0 AND s.mime_type IS NOT NULL AND s.mime_type <> ''
             LIMIT 1"
        );
        $statement->execute(['clip_id' => $clipId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'storage_disk' => (string) $row['storage_disk'],
            'object_key' => (string) $row['object_key'],
            'size_bytes' => (int) $row['size_bytes'],
            'mime_type' => (string) $row['mime_type'],
        ];
    }

    private function updateRenderState(int $clipId, int $revision, string $set, string $stateCondition): void
    {
        $statement = $this->pdo->prepare("UPDATE clips SET {$set} WHERE id = :clip_id AND render_revision = :revision AND {$stateCondition}");
        $statement->execute(['clip_id' => $clipId, 'revision' => $revision]);
    }

    /** @return array<string, mixed>|null */
    private function findByIndex(int $analysisId, int $index, bool $locked): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                    duration_seconds, viral_score, hook, reason, category
             FROM clips
             WHERE ai_analysis_id = :analysis_id AND suggestion_index = :suggestion_index
             LIMIT 1' . ($locked && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute(['analysis_id' => $analysisId, 'suggestion_index' => $index]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, int|string> */
    private function parameters(int $analysisId, int $projectId, AiClipSuggestion $clip): array
    {
        return [
            'project_id' => $projectId,
            'analysis_id' => $analysisId,
            'suggestion_index' => $clip->index(),
            'title' => $clip->title(),
            'start_time' => $this->decimal($clip->startTime()),
            'end_time' => $this->decimal($clip->endTime()),
            'duration_seconds' => $this->decimal($clip->duration()),
            'viral_score' => $clip->score(),
            'hook' => $clip->hook(),
            'reason' => $clip->reason(),
            'category' => $clip->category(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function assertSameContent(array $row, int $projectId, AiClipSuggestion $clip): void
    {
        $expected = $this->parameters((int) $row['ai_analysis_id'], $projectId, $clip);
        foreach ([
            'project_id', 'suggestion_index', 'title', 'start_time', 'end_time',
            'duration_seconds', 'viral_score', 'hook', 'reason', 'category',
        ] as $field) {
            if ((string) $row[$field] !== (string) $expected[$field]) {
                throw new RuntimeException('Stored clip conflicts with the validated suggestion.');
            }
        }
    }

    private function decimal(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
