<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;
use App\Core\Request;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ProjectSuggestionRouteIntegrationTest extends TestCase
{
    private PDO $pdo;
    private int $ownerId;
    private int $otherId;
    private int $projectId;
    private int $newClipId;

    protected function setUp(): void
    {
        $this->pdo = SafePhase5TestDatabase::using(
            getenv('TEST_DB_DSN'),
            static function (string $dsn): PDO {
                putenv('DB_DSN=' . $dsn);
                putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: ''));
                putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));

                return new PDO(
                    $dsn,
                    getenv('TEST_DB_USERNAME') ?: null,
                    getenv('TEST_DB_PASSWORD') ?: null,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }
        );
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $this->ownerId = $this->user($planId, 'suggestion-owner');
        $this->otherId = $this->user($planId, 'suggestion-other');
        $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, 'Owned suggestions', 'suggestions_ready', 100)")
            ->execute([$this->ownerId, hash('sha256', 'suggestion-project-' . $this->ownerId)]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO project_sources
                (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes,
                 duration_seconds, width, height, video_codec, has_audio, status)
             VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 240, 1920, 1080, 'h264', 1, 'ready')"
        )->execute([$this->projectId, 'imports/' . $this->projectId . '/source.mp4']);

        $oldAnalysis = $this->analysis('prompt-v1', 'Resumo antigo');
        $this->clip($oldAnalysis, 'Corte antigo', 0);
        $currentAnalysis = $this->analysis('prompt-v2', 'Resumo atual seguro');
        $this->newClipId = $this->clip($currentAnalysis, 'Corte atual um', 0);
        $this->clip($currentAnalysis, 'Corte atual dois', 1);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
    }

    public function testStatusSummaryCountAndCardsUseTheSameCurrentAnalysis(): void
    {
        $projects = new ProjectRepository($this->pdo);
        $clips = new ClipRepository($this->pdo);

        $status = $projects->statusForOwnedProject($this->projectId, $this->ownerId);
        $detail = $projects->detailForOwnedProject($this->projectId, $this->ownerId);
        $suggestions = $clips->suggestionsForOwnedProject($this->projectId, $this->ownerId);

        self::assertSame('completed', $status['analysis_status']);
        self::assertSame(2, (int) $status['suggestions_count']);
        self::assertSame('Resumo atual seguro', $detail['video_summary']);
        self::assertSame(['Corte atual um', 'Corte atual dois'], array_column($suggestions, 'title'));
        self::assertSame($this->newClipId, (int) $suggestions[0]['id']);
        self::assertNull($projects->detailForOwnedProject($this->projectId, $this->otherId));
        self::assertSame([], $clips->suggestionsForOwnedProject($this->projectId, $this->otherId));
        foreach ($suggestions as $suggestion) {
            self::assertSame([
                'id', 'title', 'start_time', 'end_time', 'render_start_time', 'render_end_time',
                'duration_seconds', 'viral_score', 'hook', 'reason', 'category', 'status',
                'output_aspect_ratio', 'reframe_mode',
            ], array_keys($suggestion));
            self::assertSame('original', $suggestion['output_aspect_ratio']);
            self::assertSame('original', $suggestion['reframe_mode']);
            foreach ([
                'render_revision',
                'output_width',
                'output_height',
                'detector_version',
                'keyframes',
                'profile_id',
                'render_profile_id',
                'object_key',
                'absolute_path',
            ] as $privateField) {
                self::assertArrayNotHasKey($privateField, $suggestion);
            }
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAuthenticatedRouteRendersOwnedCurrentAnalysisAndHidesForeignProject(): void
    {
        $this->pdo->prepare(
            "UPDATE clips
             SET status = 'failed', render_start_time = 7.125, render_end_time = 22.875,
                 output_file = 'processed/private-object-key.mp4',
                 thumbnail = 'thumbnails/private-object-key.jpg'
             WHERE id = ?"
        )->execute([$this->newClipId]);
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        Session::put('user_id', $this->ownerId);
        $owned = $router->dispatch(Request::fake('GET', '/projetos/' . $this->projectId));

        self::assertSame(200, $owned->status());
        self::assertStringContainsString('Resumo atual seguro', $owned->body());
        self::assertStringContainsString('Corte atual um', $owned->body());
        self::assertStringContainsString('value="7.125"', $owned->body());
        self::assertStringContainsString('value="22.875"', $owned->body());
        self::assertStringNotContainsString('Corte antigo', $owned->body());
        self::assertStringNotContainsString('private-object-key', $owned->body());
        self::assertStringNotContainsString('output_file', $owned->body());

        Session::put('user_id', $this->otherId);
        $foreign = $router->dispatch(Request::fake('GET', '/projetos/' . $this->projectId));
        $unknown = $router->dispatch(Request::fake('GET', '/projetos/999999999'));
        self::assertSame(404, $foreign->status());
        self::assertSame($unknown->body(), $foreign->body());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRenderRouteCreatesManualSnapshotWithMinimalJobPayload(): void
    {
        $ledgerBefore = $this->ledgerCount();
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        Session::put('user_id', $this->ownerId);

        $response = $router->dispatch(Request::fake('POST', '/clips/' . $this->newClipId . '/render', [
            '_token' => Csrf::token(),
            'start_time' => '12.500',
            'end_time' => '14.500',
            'aspect_ratio' => '9:16',
            'reframe_mode' => 'manual',
            'focus_x' => '0.250000',
            'focus_y' => '0.500000',
            'reframe_keyframes' => '',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/projetos/' . $this->projectId, $response->header('Location'));
        $plan = (new ClipRenderProfileRepository($this->pdo))->findForClipRevision($this->newClipId, 1);
        self::assertNotNull($plan);
        self::assertSame('manual', $plan->mode());
        self::assertSame('9:16', $plan->aspectRatio()->value());
        self::assertSame('0.250000', $plan->keyframes()[0]->centerXDecimal());
        $job = $this->pdo->prepare(
            "SELECT payload_json, idempotency_key FROM processing_jobs WHERE project_id = ? AND type = 'render_clip'"
        );
        $job->execute([$this->projectId]);
        $row = $job->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(
            ['clip_id' => $this->newClipId, 'render_revision' => 1],
            json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            hash('sha256', 'clip-render:' . $this->newClipId . ':v1'),
            $row['idempotency_key']
        );
        self::assertSame($ledgerBefore, $this->ledgerCount());
    }

    private function analysis(string $promptVersion, string $summary): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO ai_analyses (project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at)
             VALUES (?, ?, 'fake-model', 'completed', ?, JSON_OBJECT('video_summary', ?, 'clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        );
        $statement->execute([$this->projectId, $promptVersion, $summary, $summary]);

        return (int) $this->pdo->lastInsertId();
    }

    private function clip(int $analysisId, string $title, int $index): int
    {
        $start = $index * 31;
        $statement = $this->pdo->prepare(
            "INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category, status)
             VALUES (?, ?, ?, ?, ?, ?, 30.000, 91, 'Gancho seguro', 'Motivo seguro', 'insight', 'suggested')"
        );
        $statement->execute([$this->projectId, $analysisId, $index, $title, $start, $start + 30]);

        return (int) $this->pdo->lastInsertId();
    }

    private function user(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Suggestions', $prefix . '-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function ledgerCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?');
        $statement->execute([$this->ownerId]);

        return (int) $statement->fetchColumn();
    }
}
