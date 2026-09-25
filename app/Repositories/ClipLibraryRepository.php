<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ClipLibraryRepository
{
    private const MAX_PER_PAGE = 24;

    /** @var list<string> */
    private const FILTERS = ['recent', 'processing', 'completed', 'failed'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   items:list<array{
     *     id:int,project_id:int,project_name:string,source_type:string,source_name:string,
     *     title:string,status:string,display_duration_seconds:float,viral_score:int,hook:string,
     *     reason:string,category:string,output_aspect_ratio:string,reframe_mode:string,
     *     created_at:string,updated_at:string,rendered_at:?string,has_thumbnail:bool,has_download:bool
     *   }>,
     *   filter:string,page:int,per_page:int,total:int,last_page:int
     * }
     */
    public function paginateForUser(int $userId, string $filter, int $page, int $perPage = 24, ?int $projectId = null): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'recent';
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $projectId = $projectId !== null && $projectId > 0 ? $projectId : null;
        if ($userId < 1) {
            return $this->emptyPage($filter, $perPage);
        }
        $project = $projectId === null ? null : $this->ownedProject($userId, $projectId);
        if ($projectId !== null && $project === null) {
            $projectId = null;
        }

        $where = $this->whereClause($filter) . ($projectId !== null ? ' AND c.project_id = :project_id' : '');
        $from = $this->fromClause();
        $count = $this->pdo->prepare('SELECT COUNT(c.id) ' . $from . ' ' . $where);
        $count->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($projectId !== null) {
            $count->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        if ($total === 0) {
            return $this->emptyPage($filter, $perPage) + ($project !== null ? ['project' => $project] : []);
        }

        $statement = $this->pdo->prepare(
            'SELECT c.id, c.project_id, p.name AS project_name,
                    s.source_type, s.original_name AS source_original_name,
                    p.source_filename AS project_source_filename,
                    c.title, c.status,
                    CASE
                        WHEN c.render_start_time IS NOT NULL
                         AND c.render_end_time IS NOT NULL
                         AND c.render_end_time > c.render_start_time
                        THEN c.render_end_time - c.render_start_time
                        ELSE c.duration_seconds
                    END AS display_duration_seconds,
                    c.viral_score, c.hook, c.reason, c.category,
                    COALESCE(rp.aspect_ratio, \'original\') AS output_aspect_ratio,
                    COALESCE(rp.reframe_mode, \'original\') AS reframe_mode,
                    c.created_at, c.updated_at, c.rendered_at,
                    CASE
                        WHEN c.status = \'completed\'
                         AND c.thumbnail IS NOT NULL AND c.thumbnail <> \'\'
                         AND c.thumbnail_size_bytes IS NOT NULL AND c.thumbnail_size_bytes > 0
                        THEN 1 ELSE 0
                    END AS has_thumbnail,
                    CASE
                        WHEN c.status = \'completed\'
                         AND c.output_file IS NOT NULL AND c.output_file <> \'\'
                         AND c.output_size_bytes IS NOT NULL AND c.output_size_bytes > 0
                        THEN 1 ELSE 0
                    END AS has_download
             ' . $from . ' ' . $where . '
             ORDER BY ' . ($projectId !== null ? 'c.suggestion_index ASC, c.id ASC' : 'c.updated_at DESC, c.id DESC') . '
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($projectId !== null) {
            $statement->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->project($row);
        }

        return [
            'items' => $items,
            'filter' => $filter,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ] + ($project !== null ? ['project' => $project] : []);
    }

    /** @return array{id:int,name:string}|null */
    private function ownedProject(int $userId, int $projectId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $projectId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    private function fromClause(): string
    {
        return 'FROM clips c
                INNER JOIN projects p ON p.id = c.project_id
                INNER JOIN ai_analyses a ON a.id = c.ai_analysis_id
                INNER JOIN project_sources s ON s.project_id = c.project_id
                LEFT JOIN clip_render_profiles rp
                  ON rp.clip_id = c.id AND rp.render_revision = c.render_revision';
    }

    private function whereClause(string $filter): string
    {
        $status = match ($filter) {
            'processing' => " AND c.status IN ('approved', 'queued', 'rendering')",
            'completed' => " AND c.status = 'completed'",
            'failed' => " AND c.status = 'failed'",
            default => '',
        };

        return 'WHERE p.user_id = :user_id
                  AND a.id = (
                      SELECT MAX(current_analysis.id)
                      FROM ai_analyses current_analysis
                      WHERE current_analysis.project_id = c.project_id
                  )' . $status;
    }

    /** @param array<string,mixed> $row */
    private function project(array $row): array
    {
        $sourceType = (string) $row['source_type'];

        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'project_name' => (string) $row['project_name'],
            'source_type' => $sourceType,
            'source_name' => $this->sourceName(
                $sourceType,
                $row['source_original_name'] ?? null,
                $row['project_source_filename'] ?? null
            ),
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'display_duration_seconds' => (float) $row['display_duration_seconds'],
            'viral_score' => (int) $row['viral_score'],
            'hook' => (string) $row['hook'],
            'reason' => (string) $row['reason'],
            'category' => (string) $row['category'],
            'output_aspect_ratio' => (string) $row['output_aspect_ratio'],
            'reframe_mode' => (string) $row['reframe_mode'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'rendered_at' => $row['rendered_at'] === null ? null : (string) $row['rendered_at'],
            'has_thumbnail' => (bool) $row['has_thumbnail'],
            'has_download' => (bool) $row['has_download'],
        ];
    }

    private function sourceName(string $sourceType, mixed $originalName, mixed $fallbackName): string
    {
        if ($sourceType === 'direct_url') {
            return 'Vídeo importado por URL';
        }

        foreach ([$originalName, $fallbackName] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', trim($candidate));
            $basename = basename($normalized);
            if ($basename !== '' && $basename !== '.' && $basename !== '..') {
                return $basename;
            }
        }

        return 'Vídeo enviado';
    }

    /** @return array{items:array{},filter:string,page:int,per_page:int,total:int,last_page:int} */
    private function emptyPage(string $filter, int $perPage): array
    {
        return [
            'items' => [],
            'filter' => $filter,
            'page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'last_page' => 1,
        ];
    }
}
