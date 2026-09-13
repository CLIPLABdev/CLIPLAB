<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\ClipLibraryRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ClipLibraryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $ownerId;
    private int $otherId;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $suffix = bin2hex(random_bytes(8));
        $this->ownerId = $this->user($planId, 'library-owner-' . $suffix);
        $this->otherId = $this->user($planId, 'library-other-' . $suffix);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->ownerId, $this->otherId)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
    }

    public function testOwnershipCurrentAnalysisFiltersProjectionAndPublicDerivationsStayConsistent(): void
    {
        $uploadProject = $this->project(
            $this->ownerId,
            'Projeto upload',
            'upload',
            'C:\\private\\incoming\\episode-final.mp4',
            'server/private/fallback.mp4'
        );
        $oldAnalysis = $this->analysis($uploadProject, 'old');
        $oldClip = $this->clip($uploadProject, $oldAnalysis, 0, 'Old private clip', 'completed', '2026-09-06 23:59:00', [
            'output_file' => 'old/private/video.mp4',
            'output_size_bytes' => 100,
            'thumbnail' => 'old/private/thumb.jpg',
            'thumbnail_size_bytes' => 20,
        ]);
        $currentAnalysis = $this->analysis($uploadProject, 'current');
        $suggested = $this->clip($uploadProject, $currentAnalysis, 0, 'Upload suggested', 'suggested', '2026-09-06 10:00:00');
        $approved = $this->clip($uploadProject, $currentAnalysis, 1, 'Upload approved', 'approved', '2026-09-06 11:00:00');
        $queued = $this->clip($uploadProject, $currentAnalysis, 2, 'Upload queued', 'queued', '2026-09-06 12:00:00');
        $rendering = $this->clip($uploadProject, $currentAnalysis, 3, 'Upload rendering', 'rendering', '2026-09-06 13:00:00');
        $completed = $this->clip($uploadProject, $currentAnalysis, 4, 'Upload completed', 'completed', '2026-09-06 14:00:00', [
            'duration_seconds' => 30.0,
            'render_start_time' => 10.5,
            'render_end_time' => 25.75,
            'render_revision' => 2,
            'output_file' => 'processed/private-owner-video.mp4',
            'output_size_bytes' => 4096,
            'thumbnail' => 'thumbnails/private-owner-thumb.jpg',
            'thumbnail_size_bytes' => 512,
            'rendered_at' => '2026-09-06 14:00:30',
        ]);
        $this->profile($completed, 1, '9:16', 'center', 720, 1280);
        $this->profile($completed, 2, '4:5', 'manual', 720, 900);

        $urlProject = $this->project(
            $this->ownerId,
            'Projeto URL',
            'direct_url',
            null,
            null,
            'https://cdn.example.test/private/video.mp4?token=do-not-leak'
        );
        $urlAnalysis = $this->analysis($urlProject, 'url-current');
        $failed = $this->clip($urlProject, $urlAnalysis, 0, 'URL failed', 'failed', '2026-09-06 15:00:00');

        $unixProject = $this->project(
            $this->ownerId,
            'Projeto Unix',
            'upload',
            '/srv/private/uploads/clip-two.mov'
        );
        $unixAnalysis = $this->analysis($unixProject, 'unix-current');
        $missingAssets = $this->clip($unixProject, $unixAnalysis, 0, 'Unix completed', 'completed', '2026-09-06 16:00:00', [
            'duration_seconds' => 12.5,
            'render_start_time' => 9.0,
            'render_end_time' => 9.0,
            'render_revision' => 3,
            'output_file' => 'processed/present-but-empty.mp4',
            'output_size_bytes' => 0,
            'thumbnail' => null,
            'thumbnail_size_bytes' => null,
        ]);

        $foreignProject = $this->project($this->otherId, 'Foreign project', 'upload', 'foreign-secret.mp4');
        $foreignAnalysis = $this->analysis($foreignProject, 'foreign-current');
        $foreignClip = $this->clip($foreignProject, $foreignAnalysis, 0, 'Foreign clip', 'completed', '2026-09-06 18:00:00', [
            'output_file' => 'processed/foreign.mp4',
            'output_size_bytes' => 50,
            'thumbnail' => 'thumbnails/foreign.jpg',
            'thumbnail_size_bytes' => 10,
        ]);

        $repository = new ClipLibraryRepository($this->pdo);
        $recent = $repository->paginateForUser($this->ownerId, 'recent', 1, 24);

        self::assertSame([$missingAssets, $failed, $completed, $rendering, $queued, $approved, $suggested], array_column($recent['items'], 'id'));
        self::assertSame(7, $recent['total']);
        self::assertSame(1, $recent['page']);
        self::assertSame(1, $recent['last_page']);
        self::assertNotContains($oldClip, array_column($recent['items'], 'id'));
        self::assertNotContains($foreignClip, array_column($recent['items'], 'id'));
        self::assertSame([$approved, $queued, $rendering], array_reverse(array_column(
            $repository->paginateForUser($this->ownerId, 'processing', 1, 24)['items'],
            'id'
        )));
        self::assertSame([$missingAssets, $completed], array_column(
            $repository->paginateForUser($this->ownerId, 'completed', 1, 24)['items'],
            'id'
        ));
        self::assertSame([$failed], array_column(
            $repository->paginateForUser($this->ownerId, 'failed', 1, 24)['items'],
            'id'
        ));
        self::assertSame([$foreignClip], array_column(
            $repository->paginateForUser($this->otherId, 'completed', 1, 24)['items'],
            'id'
        ));

        $byId = [];
        foreach ($recent['items'] as $item) {
            $byId[$item['id']] = $item;
            self::assertSame([
                'id', 'project_id', 'project_name', 'source_type', 'source_name', 'title', 'status',
                'display_duration_seconds', 'viral_score', 'hook', 'reason', 'category',
                'output_aspect_ratio', 'reframe_mode', 'created_at', 'updated_at', 'rendered_at',
                'has_thumbnail', 'has_download',
            ], array_keys($item));
        }

        self::assertSame('episode-final.mp4', $byId[$completed]['source_name']);
        self::assertSame('upload', $byId[$completed]['source_type']);
        self::assertSame(15.25, $byId[$completed]['display_duration_seconds']);
        self::assertSame('4:5', $byId[$completed]['output_aspect_ratio']);
        self::assertSame('manual', $byId[$completed]['reframe_mode']);
        self::assertTrue($byId[$completed]['has_thumbnail']);
        self::assertTrue($byId[$completed]['has_download']);
        self::assertSame('Vídeo importado por URL', $byId[$failed]['source_name']);
        self::assertSame('direct_url', $byId[$failed]['source_type']);
        self::assertSame('clip-two.mov', $byId[$missingAssets]['source_name']);
        self::assertSame(12.5, $byId[$missingAssets]['display_duration_seconds']);
        self::assertFalse($byId[$missingAssets]['has_thumbnail']);
        self::assertFalse($byId[$missingAssets]['has_download']);
        self::assertSame('original', $byId[$suggested]['output_aspect_ratio']);
        self::assertSame('original', $byId[$suggested]['reframe_mode']);
        self::assertIsInt($byId[$completed]['id']);
        self::assertIsInt($byId[$completed]['project_id']);
        self::assertIsInt($byId[$completed]['viral_score']);
        self::assertIsFloat($byId[$completed]['display_duration_seconds']);
        self::assertIsString($byId[$completed]['updated_at']);
        self::assertIsString($byId[$completed]['rendered_at']);

        $encoded = json_encode($recent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([
            'source_url', 'object_key', 'output_file', 'thumbnail_size_bytes', 'private-owner-video',
            'private-owner-thumb', 'server/private', 'do-not-leak', 'Old private clip', 'Foreign clip',
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }
    }

    public function testPaginationCapsPageSizeOrdersTiesAndClampsBeforeOffsetMath(): void
    {
        $projectId = $this->project($this->ownerId, 'Pagination', 'upload', 'pagination.mp4');
        $analysisId = $this->analysis($projectId, 'pagination-current');
        $ids = [];
        for ($index = 0; $index < 26; ++$index) {
            $ids[] = $this->clip(
                $projectId,
                $analysisId,
                $index,
                'Page clip ' . $index,
                'suggested',
                '2026-09-06 20:00:00'
            );
        }
        $expected = array_reverse($ids);
        $repository = new ClipLibraryRepository($this->pdo);

        $first = $repository->paginateForUser($this->ownerId, 'recent', 1, 999);
        $last = $repository->paginateForUser($this->ownerId, 'recent', PHP_INT_MAX, 24);

        self::assertSame(24, $first['per_page']);
        self::assertSame(array_slice($expected, 0, 24), array_column($first['items'], 'id'));
        self::assertSame(26, $first['total']);
        self::assertSame(2, $first['last_page']);
        self::assertSame(2, $last['page']);
        self::assertSame(array_slice($expected, 24), array_column($last['items'], 'id'));
    }

    public function testUnknownFilterAndEmptyResultNormalizeToRecentFirstPage(): void
    {
        $repository = new ClipLibraryRepository($this->pdo);

        $page = $repository->paginateForUser($this->ownerId, 'private-filter', PHP_INT_MAX, 24);

        self::assertSame([
            'items' => [],
            'filter' => 'recent',
            'page' => 1,
            'per_page' => 24,
            'total' => 0,
            'last_page' => 1,
        ], $page);
        self::assertSame([], $repository->paginateForUser(-1, 'completed', 1, 24)['items']);
    }

    private function user(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Library User', $prefix . '@example.test', 'x', $planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function project(
        int $userId,
        string $name,
        string $sourceType,
        ?string $originalName,
        ?string $sourceFilename = null,
        ?string $sourceUrl = null
    ): int {
        $ingestKey = hash('sha256', 'clip-library-' . $userId . '-' . $name . '-' . random_bytes(8));
        $statement = $this->pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, source_filename, status, progress)
             VALUES (?, ?, ?, ?, 'suggestions_ready', 92)"
        );
        $statement->execute([$userId, $ingestKey, $name, $sourceFilename]);
        $projectId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            "INSERT INTO project_sources
                (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type,
                 size_bytes, source_url, duration_seconds, status)
             VALUES (?, ?, 'local', ?, ?, 'mp4', 'video/mp4', 1000, ?, 120, 'ready')"
        );
        $statement->execute([
            $projectId,
            $sourceType,
            'private/sources/' . $projectId . '/source.mp4',
            $originalName,
            $sourceUrl,
        ]);

        return $projectId;
    }

    private function analysis(int $projectId, string $name): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO ai_analyses
                (project_id, prompt_version, model, status, validated_response_json, completed_at)
             VALUES (?, ?, 'library-test', 'completed', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        );
        $statement->execute([$projectId, substr($name . '-' . bin2hex(random_bytes(4)), 0, 32)]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    private function clip(
        int $projectId,
        int $analysisId,
        int $index,
        string $title,
        string $status,
        string $updatedAt,
        array $overrides = []
    ): int {
        $data = array_replace([
            'duration_seconds' => 8.5,
            'render_start_time' => null,
            'render_end_time' => null,
            'render_revision' => 0,
            'output_file' => null,
            'output_size_bytes' => null,
            'thumbnail' => null,
            'thumbnail_size_bytes' => null,
            'rendered_at' => null,
        ], $overrides);
        $statement = $this->pdo->prepare(
            'INSERT INTO clips
                (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                 duration_seconds, viral_score, hook, reason, category, status,
                 render_start_time, render_end_time, render_revision, output_file, output_size_bytes,
                 thumbnail, thumbnail_size_bytes, rendered_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1.000, 9.500, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $projectId,
            $analysisId,
            $index,
            $title,
            $data['duration_seconds'],
            70 + ($index % 30),
            'Hook ' . $title,
            'Reason ' . $title,
            'insight',
            $status,
            $data['render_start_time'],
            $data['render_end_time'],
            $data['render_revision'],
            $data['output_file'],
            $data['output_size_bytes'],
            $data['thumbnail'],
            $data['thumbnail_size_bytes'],
            $data['rendered_at'],
            $updatedAt,
            $updatedAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function profile(int $clipId, int $revision, string $aspect, string $mode, int $width, int $height): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clip_render_profiles
                (clip_id, render_revision, aspect_ratio, reframe_mode, output_width, output_height, detector_version)
             VALUES (?, ?, ?, ?, ?, ?, NULL)'
        );
        $statement->execute([$clipId, $revision, $aspect, $mode, $width, $height]);
    }
}
