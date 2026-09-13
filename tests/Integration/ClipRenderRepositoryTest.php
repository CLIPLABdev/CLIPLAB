<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Media\StoredObject;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ClipRenderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $ownerId;
    private int $otherUserId;
    private int $projectId;
    private int $sourceId;
    private int $clipId;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['clip-render-' . $suffix, 'Clip render ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->ownerId = $this->createUser('owner-' . $suffix, $this->planId);
        $this->otherUserId = $this->createUser('other-' . $suffix, $this->planId);
        $this->pdo->prepare("INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Render fixture', 'suggestions_ready', 92)")
            ->execute([$this->ownerId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes, duration_seconds, width, height, video_codec, has_audio, status) VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 120, 1920, 1080, 'h264', 1, 'ready')")
            ->execute([$this->projectId, 'imports/' . $this->projectId . '/source.mp4']);
        $this->sourceId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, 'render-test', 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))")
            ->execute([$this->projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category) VALUES (?, ?, 0, 'Render clip', 10, 40, 30, 90, 'Hook', 'Reason', 'insight')")
            ->execute([$this->projectId, $analysisId]);
        $this->clipId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherUserId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testRenderRequestProjectionRequiresOwnershipCurrentAnalysisAndReadySource(): void
    {
        $clips = new ClipRepository($this->pdo);

        $row = $clips->findForRenderRequest($this->clipId, $this->ownerId, true);

        self::assertNotNull($row);
        self::assertSame($this->clipId, $row['id']);
        self::assertSame($this->projectId, $row['project_id']);
        self::assertSame($this->sourceId, $row['source_id']);
        self::assertSame('suggested', $row['status']);
        self::assertSame(10.0, $row['start_time']);
        self::assertSame(40.0, $row['end_time']);
        self::assertSame(120, $row['source_duration_seconds']);
        self::assertNull($row['render_start_time']);
        self::assertNull($row['render_end_time']);
        self::assertSame(0, $row['render_revision']);
        self::assertNull($clips->findForRenderRequest($this->clipId, $this->otherUserId));

        $this->pdo->prepare("UPDATE project_sources SET status = 'stored' WHERE id = ?")->execute([$this->sourceId]);
        self::assertNull($clips->findForRenderRequest($this->clipId, $this->ownerId));
        $this->pdo->prepare("UPDATE project_sources SET status = 'ready' WHERE id = ?")->execute([$this->sourceId]);

        $this->createNewerAnalysis();
        self::assertNull($clips->findForRenderRequest($this->clipId, $this->ownerId));
    }

    public function testRenderLifecyclePersistsOnlyMatchingRevisionAndPublicProjectionHidesObjectKeys(): void
    {
        $clips = new ClipRepository($this->pdo);

        $clips->queueRender($this->clipId, 12.5, 37.25, 1);
        $job = $clips->findForRenderJob($this->clipId, 1);
        self::assertNotNull($job);
        self::assertSame('queued', $job['status']);
        self::assertSame(12.5, $job['render_start_time']);
        self::assertSame(37.25, $job['render_end_time']);
        self::assertSame('imports/' . $this->projectId . '/source.mp4', $job['source']->objectKey());
        self::assertNull($clips->findForRenderJob($this->clipId, 2));

        $clips->markRendering($this->clipId, 1);
        $clips->completeRender(
            $this->clipId,
            1,
            new StoredObject('processed/1/video.mp4', 1234, str_repeat('a', 64)),
            new StoredObject('thumbnails/1/thumb.jpg', 321, str_repeat('b', 64))
        );
        $clips->markRenderFailed($this->clipId, 2, 'render_failed');

        $status = $clips->statusForOwnedClip($this->clipId, $this->ownerId);
        self::assertNotNull($status);
        self::assertSame('completed', $status['status']);
        self::assertSame(12.5, $status['render_start_time']);
        self::assertSame(37.25, $status['render_end_time']);
        self::assertArrayNotHasKey('output_file', $status);
        self::assertArrayNotHasKey('thumbnail', $status);
        self::assertNull($clips->statusForOwnedClip($this->clipId, $this->otherUserId));

        self::assertSame(
            ['object_key' => 'processed/1/video.mp4', 'size_bytes' => 1234, 'mime_type' => 'video/mp4'],
            $clips->artifactForOwnedClip($this->clipId, $this->ownerId, 'video')
        );
        self::assertSame(
            ['object_key' => 'thumbnails/1/thumb.jpg', 'size_bytes' => 321, 'mime_type' => 'image/jpeg'],
            $clips->artifactForOwnedClip($this->clipId, $this->ownerId, 'thumbnail')
        );
        self::assertNull($clips->artifactForOwnedClip($this->clipId, $this->otherUserId, 'video'));
        self::assertNull($clips->artifactForOwnedClip($this->clipId, $this->ownerId, 'invalid'));

        $this->createNewerAnalysis();
        self::assertNull($clips->findForRenderRequest($this->clipId, $this->ownerId));
        self::assertNull($clips->statusForOwnedClip($this->clipId, $this->ownerId));
        self::assertNull($clips->artifactForOwnedClip($this->clipId, $this->ownerId, 'video'));
    }

    public function testProjectRenderStateUsesActiveThenCompletedThenSuggestionsFallback(): void
    {
        $clips = new ClipRepository($this->pdo);
        $projects = new ProjectRepository($this->pdo);

        $clips->queueRender($this->clipId, 12.5, 37.25, 1);
        $projects->synchronizeRenderState($this->projectId);
        self::assertSame(['rendering', 96], $this->projectState());

        $clips->markRendering($this->clipId, 1);
        $clips->completeRender(
            $this->clipId,
            1,
            new StoredObject('processed/1/video.mp4', 1234, str_repeat('a', 64)),
            new StoredObject('thumbnails/1/thumb.jpg', 321, str_repeat('b', 64))
        );
        $projects->synchronizeRenderState($this->projectId);
        self::assertSame(['completed', 100], $this->projectState());

        $this->createNewerAnalysis();
        $this->pdo->prepare("UPDATE clips SET status = 'queued' WHERE id = ?")->execute([$this->clipId]);
        $projects->synchronizeRenderState($this->projectId);
        self::assertSame(['suggestions_ready', 92], $this->projectState());

        $this->pdo->prepare("UPDATE clips SET status = 'completed' WHERE id = ?")->execute([$this->clipId]);
        $projects->synchronizeRenderState($this->projectId);
        self::assertSame(['suggestions_ready', 92], $this->projectState());
    }

    private function createUser(string $suffix, int $planId): int
    {
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute([$suffix, $suffix . '@example.test', 'not-a-real-hash', $planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createNewerAnalysis(): void
    {
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, ?, 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))")
            ->execute([$this->projectId, 'render-test-next-' . bin2hex(random_bytes(4))]);
    }

    /** @return array{string, int} */
    private function projectState(): array
    {
        $statement = $this->pdo->prepare('SELECT status, progress FROM projects WHERE id = ?');
        $statement->execute([$this->projectId]);
        $row = $statement->fetch();

        return [(string) $row['status'], (int) $row['progress']];
    }
}
