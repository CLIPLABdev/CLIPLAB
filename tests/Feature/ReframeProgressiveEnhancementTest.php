<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use PHPUnit\Framework\TestCase;

final class ReframeProgressiveEnhancementTest extends TestCase
{
    public function testEditableProjectLoadsOneExternalModuleAtTheEndWithoutInliningWorkerOrVendor(): void
    {
        $html = $this->render();

        self::assertSame(1, substr_count($html, 'src="/assets/js/reframe-editor.js"'));
        self::assertMatchesRegularExpression(
            '/<script\s+type="module"\s+src="\/assets\/js\/reframe-editor\.js"\s+defer><\/script>/',
            $html
        );
        self::assertGreaterThan(
            strpos($html, 'src="/assets/js/clip-status.js"'),
            strpos($html, 'src="/assets/js/reframe-editor.js"')
        );
        self::assertStringNotContainsString('reframe-worker.js', $html);
        self::assertStringNotContainsString('mediapipe-tasks-vision', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]+src=)[^>]*>[\s\S]*?(?:Worker|import\s*\()/i', $html);
    }

    public function testInitialMarkupRemainsNoJavascriptSafeAndCarriesOnlyServerSanitizedInputs(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-consent-active="1"', $html);
        self::assertStringContainsString('data-source-preview-url="/clips/41/source-preview"', $html);
        self::assertStringContainsString('data-reframe-max-duration-ms="90000"', $html);
        self::assertStringContainsString('data-reframe-max-frames="180"', $html);
        self::assertStringContainsString('data-reframe-max-edge="320"', $html);
        self::assertMatchesRegularExpression('/<video(?![^>]+src=)[^>]+data-reframe-preview/', $html);
        self::assertMatchesRegularExpression('/data-reframe-auto-option[^>]*>|value="auto"[^>]*disabled[^>]*hidden/', $html);
        self::assertStringContainsString('name="focus_x" type="number"', $html);
        self::assertStringContainsString('name="focus_y" type="number"', $html);
        self::assertStringContainsString('name="reframe_keyframes" type="hidden" value=""', $html);
    }

    public function testCanonicalJsonProducedByTheEditorContractIsAcceptedByTheBackendValidator(): void
    {
        $json = '[{"at_ms":0,"center_x":0.25,"center_y":0.5},{"at_ms":2000,"center_x":0.75,"center_y":0.5}]';
        $plan = (new ReframePlanValidator())->validate(
            new ReframeSubmission('9:16', 'auto', '', '', $json),
            2000
        );

        self::assertSame('auto', $plan->mode());
        self::assertCount(2, $plan->keyframes());
        self::assertSame('0.250000', $plan->keyframes()[0]->centerXDecimal());
        self::assertSame('0.750000', $plan->keyframes()[1]->centerXDecimal());
    }

    public function testOverlayCssEnablesPointerAndKeyboardFocusOnlyWhenTheCanvasIsVisible(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/projects.css');

        self::assertMatchesRegularExpression('/\.reframe-overlay:not\(\[hidden\]\)[^}]*pointer-events:auto/s', $css);
        self::assertMatchesRegularExpression('/\.reframe-overlay:not\(\[hidden\]\)[^}]*touch-action:none/s', $css);
        self::assertMatchesRegularExpression('/\.reframe-overlay:focus-visible[^}]*outline:/s', $css);
    }

    private function render(): string
    {
        return (new View())->render('projects.show', [
            'title' => 'Projetos',
            'user' => [
                'id' => 17,
                'name' => 'Ana',
                'email' => 'ana@example.test',
                'credits' => 7,
                'plan_name' => 'Free',
                'monthly_minutes' => 60,
            ],
            'project' => [
                'id' => 31,
                'name' => 'Projeto reframe',
                'status' => 'suggestions_ready',
                'progress' => 100,
                'stage' => 'Sugestões prontas',
                'message' => 'Os cortes estão disponíveis.',
                'analysis_status' => 'completed',
                'video_summary' => null,
                'updated_at' => '2026-09-04 12:00:00',
            ],
            'clips' => [[
                'id' => 41,
                'title' => 'Corte 41',
                'start_time' => 10.0,
                'end_time' => 12.0,
                'duration_seconds' => 2.0,
                'viral_score' => 91,
                'hook' => 'Gancho',
                'reason' => 'Motivo',
                'category' => 'insight',
                'status' => 'suggested',
                'render_start_time' => null,
                'render_end_time' => null,
                'output_aspect_ratio' => 'original',
                'reframe_mode' => 'original',
                'source_preview_url' => '/clips/41/source-preview',
            ]],
            'mediaPipeConsentActive' => true,
            'reframeUiConfig' => ['max_duration_ms' => 90000, 'max_frames' => 180, 'max_edge' => 320],
        ])->body();
    }
}
