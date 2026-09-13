<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Controllers\ProjectSuggestionController;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Media\ProjectReceipt;
use PHPUnit\Framework\TestCase;

final class ProjectAiViewsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 17];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLibraryShowsRealAiStageSafeSuggestionActionAndRefreshFallback(): void
    {
        $rows = [
            ['id' => 61, 'name' => 'Sugestões', 'source_type' => 'upload', 'duration_seconds' => 90, 'size_bytes' => 1024, 'created_at' => '2026-09-04', 'status' => 'suggestions_ready', 'progress' => 100],
            ['id' => 63, 'name' => 'Renderizando', 'source_type' => 'upload', 'duration_seconds' => 90, 'size_bytes' => 1024, 'created_at' => '2026-09-04', 'status' => 'rendering', 'progress' => 96],
            ['id' => 64, 'name' => 'Concluído', 'source_type' => 'upload', 'duration_seconds' => 90, 'size_bytes' => 1024, 'created_at' => '2026-09-04', 'status' => 'completed', 'progress' => 100],
            ['id' => 62, 'name' => 'Processando', 'source_type' => 'upload', 'duration_seconds' => 90, 'size_bytes' => 1024, 'created_at' => '2026-09-04', 'status' => 'analyzing', 'progress' => 88],
        ];
        $controller = new ProjectController(new View(), new AiViewProjectCreator(), static fn (): array => $rows);

        $html = $controller->index()->body();

        self::assertStringContainsString('Sugestões prontas', $html);
        self::assertStringContainsString('href="/projetos/61"', $html);
        self::assertStringContainsString('href="/projetos/63"', $html);
        self::assertStringContainsString('href="/projetos/64"', $html);
        self::assertStringContainsString('Ver sugestões', $html);
        self::assertStringContainsString('Analisando com IA', $html);
        self::assertStringContainsString('data-project-status-url="/api/projects/62/status"', $html);
        self::assertStringContainsString('Atualizar status', $html);
        self::assertStringContainsString('data-project-suggestions-link', $html);
    }

    public function testSuggestionsStylesKeepStageVisibleOnMobileAndSupportReducedMotion(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/projects.css');

        self::assertMatchesRegularExpression('/@media\\s*\\(max-width:600px\\)[\\s\\S]*\\.library-project-card\\s+\\.status-pill\\s*\\{[^}]*display:(?:inline-)?flex/s', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion:reduce)', $css);
        self::assertStringContainsString('.suggestion-card', $css);
        self::assertMatchesRegularExpression('/@media\\s*\\(max-width:600px\\)[\\s\\S]*\\.clip-render-fields\\s*\\{[^}]*grid-template-columns:1fr/s', $css);
        self::assertMatchesRegularExpression('/@media\\s*\\(max-width:600px\\)[\\s\\S]*\\.clip-render-form\\s+\\.button\\s*\\{[^}]*width:100%/s', $css);
    }

    public function testSuggestionCardsExposeRenderControlsAndProtectedCompletedAssets(): void
    {
        $html = (new View())->render('projects.show', [
            'title' => 'Projetos',
            'user' => ['id' => 17, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60],
            'project' => ['id' => 61, 'name' => 'Projeto IA', 'status' => 'suggestions_ready', 'progress' => 92, 'stage' => 'Sugestões prontas', 'message' => 'Disponíveis.', 'analysis_status' => 'completed', 'video_summary' => null, 'updated_at' => '2026-09-04'],
            'clips' => [
                ['id' => 71, 'title' => 'Sugestão', 'start_time' => 12.5, 'end_time' => 35.25, 'duration_seconds' => 22.75, 'viral_score' => 90, 'hook' => 'Gancho', 'reason' => 'Motivo', 'category' => 'insight', 'status' => 'suggested'],
                ['id' => 72, 'title' => 'Pronto', 'start_time' => 40.0, 'end_time' => 70.0, 'duration_seconds' => 30.0, 'viral_score' => 88, 'hook' => 'Gancho', 'reason' => 'Motivo', 'category' => 'insight', 'status' => 'completed'],
            ],
        ])->body();

        self::assertStringContainsString('Aprovar e renderizar', $html);
        self::assertStringContainsString('name="start_time"', $html);
        self::assertStringContainsString('name="end_time"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('/assets/js/clip-status.js', $html);
        self::assertStringContainsString('Baixar MP4', $html);
        self::assertStringNotContainsString('output_file', $html);
    }

    public function testProjectDetailKeepsRealProjectAndClipStatesWithSafePerClipOldInput(): void
    {
        Session::flash('clip_render_feedback', 'Revise o intervalo informado.');
        Session::flash('clip_render_errors', ['start_time' => 'Início inválido.']);
        Session::flash('clip_render_old', ['clip_id' => 71, 'start_time' => '14.125', 'end_time' => '45.500']);
        $controller = new ProjectSuggestionController(
            new View(),
            static fn (int $projectId): array => [
                'id' => $projectId, 'name' => 'Projeto real', 'status' => 'rendering', 'progress' => 96,
                'analysis_status' => 'completed', 'video_summary' => 'Resumo', 'updated_at' => '2026-09-04 12:00:00',
            ],
            static fn (): array => [
                self::clip(71, 'suggested', 10.0, 40.0),
                self::clip(72, 'queued', 20.0, 50.0, 21.25, 48.75),
                self::clip(73, 'rendering', 30.0, 60.0, 31.0, 59.0),
                self::clip(74, 'completed', 40.0, 70.0, 41.5, 69.5) + ['output_file' => 'private/video.mp4', 'thumbnail' => 'private/thumb.jpg'],
                self::clip(75, 'failed', 50.0, 80.0, 52.25, 78.75),
            ]
        );

        $html = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31'])->body();

        self::assertStringContainsString('status-rendering', $html);
        self::assertStringContainsString('data-clip-card="71"', $html);
        self::assertStringContainsString('action="/clips/71/render"', $html);
        self::assertStringContainsString('value="14.125"', $html);
        self::assertSame(1, substr_count($html, 'value="14.125"'));
        self::assertStringContainsString('Início inválido.', $html);
        self::assertStringContainsString('data-clip-status-url="/api/clips/72/status"', $html);
        self::assertStringContainsString('Na fila para renderização', $html);
        self::assertStringContainsString('Renderizando vídeo', $html);
        self::assertStringContainsString('Atualizar página', $html);
        self::assertStringContainsString('src="/clips/74/thumbnail"', $html);
        self::assertStringContainsString('href="/clips/74/download"', $html);
        self::assertStringContainsString('value="52.25"', $html);
        self::assertStringContainsString('value="78.75"', $html);
        self::assertStringContainsString('data-clip-status', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('data-clip-message', $html);
        self::assertStringContainsString('data-clip-thumbnail', $html);
        self::assertStringContainsString('data-clip-download', $html);
        self::assertSame(2, substr_count($html, 'data-reframe-editor'));
        self::assertStringContainsString('Original · Original', $html);
        self::assertStringContainsString('data-source-preview-url="/clips/71/source-preview"', $html);
        self::assertStringContainsString('data-reframe-max-duration-ms="90000"', $html);
        self::assertStringNotContainsString('data-reframe-editor data-clip-id="72"', $html);
        self::assertStringNotContainsString('data-reframe-editor data-clip-id="73"', $html);
        self::assertStringNotContainsString('data-reframe-editor data-clip-id="74"', $html);
        self::assertStringNotContainsString('96%', $html);
        self::assertStringNotContainsString('private/video.mp4', $html);
        self::assertStringNotContainsString('private/thumb.jpg', $html);
    }

    public function testDashboardUsesHumanAiLabelsAndLinksReadySuggestions(): void
    {
        $response = (new View())->render('dashboard.index', [
            'title' => 'Visão geral',
            'user' => ['id' => 17, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60, 'status' => 'active'],
            'metrics' => [
                'projects' => 3,
                'processed' => 3,
                'minutes' => 58,
                'minutes_used' => 2,
                'credits' => 7,
                'storage_bytes' => 1024,
                'recent' => [
                    ['id' => 61, 'name' => 'Projeto IA', 'status' => 'suggestions_ready', 'original_duration_seconds' => 0, 'processed_duration_seconds' => 0, 'storage_bytes' => 1024, 'created_at' => '2026-09-04'],
                    ['id' => 63, 'name' => 'Projeto render', 'status' => 'rendering', 'original_duration_seconds' => 0, 'processed_duration_seconds' => 0, 'storage_bytes' => 1024, 'created_at' => '2026-09-04'],
                    ['id' => 64, 'name' => 'Projeto pronto', 'status' => 'completed', 'original_duration_seconds' => 0, 'processed_duration_seconds' => 0, 'storage_bytes' => 1024, 'created_at' => '2026-09-04'],
                ],
            ],
        ]);

        self::assertStringContainsString('Sugestões prontas', $response->body());
        self::assertStringContainsString('href="/projetos/61"', $response->body());
        self::assertStringContainsString('href="/projetos/63"', $response->body());
        self::assertStringContainsString('href="/projetos/64"', $response->body());
        self::assertStringContainsString('Renderizando', $response->body());
        self::assertStringNotContainsString('>Suggestions_ready<', $response->body());
        self::assertStringContainsString('.status-suggestions_ready', (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css'));
    }

    public function testSubtitleFailureRemainsVisibleAfterReloadWithoutLeakingUnknownErrors(): void
    {
        $controller = new ProjectSuggestionController(new View(), static fn (): array => [
            'id'=>31,'name'=>'Legendas','status'=>'suggestions_ready','progress'=>92,
            'analysis_status'=>'completed','video_summary'=>null,'updated_at'=>'2026-09-07',
        ], static fn (): array => [
            self::clip(75,'failed',0,10) + ['render_error_code'=>'subtitle_empty'],
            self::clip(76,'failed',0,10) + ['render_error_code'=>'secret-provider-payload'],
        ]);
        $html=$controller->show(Request::fake('GET','/projetos/31'),['id'=>'31'])->body();
        self::assertStringContainsString('Não foi possível encontrar fala para gerar legendas neste corte.', $html);
        self::assertStringNotContainsString('secret-provider-payload', $html);
        self::assertStringNotContainsString('href="/clips/75/download"', $html);
    }

    /** @return array<string, int|float|string|null> */
    private static function clip(int $id, string $status, float $start, float $end, ?float $renderStart = null, ?float $renderEnd = null): array
    {
        return [
            'id' => $id, 'title' => 'Corte ' . $id, 'start_time' => $start, 'end_time' => $end,
            'duration_seconds' => $end - $start, 'viral_score' => 90, 'hook' => 'Gancho',
            'reason' => 'Motivo', 'category' => 'insight', 'status' => $status,
            'render_start_time' => $renderStart, 'render_end_time' => $renderEnd,
        ];
    }
}

final class AiViewProjectCreator implements ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
    {
        return new ProjectReceipt(1, 'queued', true);
    }

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt
    {
        return new ProjectReceipt(1, 'queued', true);
    }
}
