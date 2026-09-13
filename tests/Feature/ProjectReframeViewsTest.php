<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectSuggestionController;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ProjectReframeViewsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 17];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSuggestedAndFailedCardsExposeTheCompleteSafeEditorContract(): void
    {
        $html = $this->render([
            $this->clip(41, 'suggested'),
            $this->clip(42, 'failed'),
        ], false);

        self::assertSame(2, substr_count($html, 'data-reframe-editor'));
        self::assertStringContainsString('action="/clips/41/render"', $html);
        self::assertStringContainsString('name="start_time"', $html);
        self::assertStringContainsString('name="end_time"', $html);
        self::assertStringContainsString('name="aspect_ratio"', $html);
        foreach (['original', '9:16', '1:1', '16:9', '4:5'] as $ratio) {
            self::assertStringContainsString('option value="' . $ratio . '"', $html);
        }
        self::assertStringContainsString('name="reframe_mode"', $html);
        foreach (['original', 'center', 'manual'] as $mode) {
            self::assertStringContainsString('option value="' . $mode . '"', $html);
        }
        self::assertMatchesRegularExpression('/<option value="auto"[^>]*disabled[^>]*hidden[^>]*data-reframe-auto-option/', $html);
        self::assertStringContainsString('name="focus_x" type="number" min="0" max="1" step="0.000001"', $html);
        self::assertStringContainsString('name="focus_y" type="number" min="0" max="1" step="0.000001"', $html);
        self::assertStringContainsString('name="reframe_keyframes" type="hidden"', $html);
        self::assertStringContainsString('data-reframe-keyframes', $html);
        self::assertStringContainsString('type="button" data-reframe-preview-open', $html);
        self::assertStringContainsString('type="button" data-reframe-focus-edit', $html);
        self::assertStringContainsString('type="button" data-reframe-auto', $html);
        self::assertStringContainsString('data-reframe-preview', $html);
        self::assertStringContainsString('class="reframe-preview-stage"', $html);
        self::assertStringContainsString('data-reframe-overlay', $html);
        self::assertStringContainsString('data-reframe-status', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('<details', $html);
        self::assertStringContainsString('Resumo do enquadramento', $html);
        self::assertStringContainsString('data-source-preview-url="/clips/41/source-preview"', $html);
        self::assertStringContainsString('data-consent-active="0"', $html);
        self::assertStringContainsString('data-reframe-max-duration-ms="90000"', $html);
        self::assertStringContainsString('data-reframe-max-frames="180"', $html);
        self::assertStringContainsString('data-reframe-max-edge="320"', $html);
        self::assertDoesNotMatchRegularExpression('/<video[^>]+src=/i', $html);
        self::assertStringNotContainsString('mediapipe-tasks-vision', $html);
        self::assertStringNotContainsString('reframe-worker.js', $html);
    }

    public function testPreviewStageContainsOnlyTheVideoAndItsOverlay(): void
    {
        $html = $this->render([$this->clip(41, 'suggested')], false);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $stages = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " reframe-preview-stage ")]'
        );
        self::assertNotFalse($stages);
        self::assertSame(1, $stages->length);
        $stage = $stages->item(0);
        self::assertNotNull($stage);

        $children = [];
        foreach ($stage->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = strtolower($child->tagName);
            }
        }
        self::assertSame(['video', 'canvas'], $children);
        self::assertSame(0, $xpath->query('.//button|.//*[@data-reframe-status]', $stage)->length);

        $canvas = $xpath->query('.//canvas[@data-reframe-overlay]', $stage)->item(0);
        self::assertInstanceOf(\DOMElement::class, $canvas);
        self::assertSame('true', $canvas->getAttribute('aria-hidden'));
        self::assertSame('-1', $canvas->getAttribute('tabindex'));
        self::assertSame('false', $canvas->getAttribute('data-focus-editing'));
        self::assertNotSame('', $canvas->getAttribute('id'));

        $toggle = $xpath->query(
            '//*[@data-reframe-focus-edit and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " reframe-preview-stage ")])]'
        )->item(0);
        self::assertInstanceOf(\DOMElement::class, $toggle);
        self::assertSame('false', $toggle->getAttribute('aria-pressed'));
        self::assertSame($canvas->getAttribute('id'), $toggle->getAttribute('aria-controls'));
        self::assertTrue($toggle->hasAttribute('disabled'));
        self::assertTrue($toggle->hasAttribute('hidden'));
    }

    public function testConsentDisclosureExplainsLocalProcessingAndTechnicalMetricsBeforeAction(): void
    {
        $withoutConsent = $this->render([$this->clip(41, 'suggested')], false);
        self::assertStringContainsString('processados neste dispositivo', $withoutConsent);
        self::assertStringContainsString('métricas técnicas', $withoutConsent);
        self::assertStringContainsString('action="/privacidade/consentimentos/mediapipe"', $withoutConsent);
        self::assertStringContainsString('name="return_project_id" value="31"', $withoutConsent);
        self::assertLessThan(
            strpos($withoutConsent, 'data-reframe-auto'),
            strpos($withoutConsent, 'action="/privacidade/consentimentos/mediapipe"')
        );

        $withConsent = $this->render([$this->clip(41, 'suggested')], true);
        self::assertStringContainsString('action="/privacidade/consentimentos/mediapipe/revogar"', $withConsent);
        self::assertStringContainsString('data-consent-active="1"', $withConsent);
    }

    public function testQueuedRenderingAndCompletedCardsExposeBadgesButNoEditor(): void
    {
        $html = $this->render([
            $this->clip(51, 'queued', '9:16', 'center'),
            $this->clip(52, 'rendering', '1:1', 'manual'),
            $this->clip(53, 'completed', '4:5', 'auto'),
        ], true);

        self::assertStringNotContainsString('data-reframe-editor', $html);
        self::assertStringNotContainsString('action="/clips/51/render"', $html);
        self::assertStringContainsString('9:16 · Centralizado', $html);
        self::assertStringContainsString('1:1 · Foco manual', $html);
        self::assertStringContainsString('4:5 · Automático', $html);
        self::assertStringContainsString('Na fila para renderização', $html);
        self::assertStringContainsString('Renderizando vídeo', $html);
        self::assertStringContainsString('Concluído', $html);
    }

    public function testControllerPreservesFiveArgumentConstructionAndProjectsSafeDefaults(): void
    {
        $capture = $this->captureView(static function (View $view): ProjectSuggestionController {
            return new ProjectSuggestionController(
                $view,
                static fn (int $projectId): array => self::project($projectId),
                static fn (): array => [self::clip(41, 'suggested')],
                static fn (int $userId): array => ['id' => $userId, 'name' => 'Ana'],
                null
            );
        });

        self::assertArrayHasKey('mediaPipeConsentActive', $capture);
        self::assertArrayHasKey('reframeUiConfig', $capture);
        self::assertFalse($capture['mediaPipeConsentActive']);
        self::assertSame(['max_duration_ms' => 90000, 'max_frames' => 180, 'max_edge' => 320], $capture['reframeUiConfig']);
        self::assertSame('/clips/41/source-preview', $capture['clips'][0]['source_preview_url']);
    }

    public function testControllerCallsConsentOnceClampsConfigAndKeepsClipProjectionPrivate(): void
    {
        $consentCalls = 0;
        $capture = $this->captureView(static function (View $view) use (&$consentCalls): ProjectSuggestionController {
            return new ProjectSuggestionController(
                $view,
                static fn (int $projectId): array => self::project($projectId),
                static fn (): array => [self::clip(41, 'suggested', 'private-ratio', 'private-mode') + [
                    'object_key' => 'private/source.mp4',
                    'absolute_path' => 'C:\\private\\source.mp4',
                    'detector_version' => 'private-detector',
                    'keyframes' => [['private' => true]],
                    'profile_id' => 991,
                    'mediaPipeConsentActive' => 'private-consent',
                    'reframeUiConfig' => ['private-config'],
                ]],
                null,
                null,
                static function (int $userId) use (&$consentCalls): bool {
                    ++$consentCalls;
                    self::assertSame(17, $userId);

                    return true;
                },
                [
                    'reframe_max_duration_seconds' => 999,
                    'reframe_preview_max_frames' => 1,
                    'reframe_preview_max_edge' => 'not-an-integer',
                    'private_root' => 'C:\\private',
                    'mediapipe_asset_version' => 'private-version',
                ]
            );
        });

        self::assertSame(1, $consentCalls);
        self::assertTrue($capture['mediaPipeConsentActive']);
        self::assertSame(['max_duration_ms' => 180000, 'max_frames' => 2, 'max_edge' => 320], $capture['reframeUiConfig']);
        self::assertSame([
            'id', 'title', 'start_time', 'end_time', 'duration_seconds', 'viral_score', 'hook', 'reason',
            'category', 'status', 'render_start_time', 'render_end_time', 'output_aspect_ratio',
            'reframe_mode', 'source_preview_url',
        ], array_keys($capture['clips'][0]));
        self::assertSame('original', $capture['clips'][0]['output_aspect_ratio']);
        self::assertSame('original', $capture['clips'][0]['reframe_mode']);
        self::assertSame('/clips/41/source-preview', $capture['clips'][0]['source_preview_url']);
        self::assertStringNotContainsString('private', json_encode($capture['clips'], JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('private_root', $capture['reframeUiConfig']);
        self::assertArrayNotHasKey('mediapipe_asset_version', $capture['reframeUiConfig']);
    }

    public function testOldManualInputKeepsFocusButDropsIncompatibleRawKeyframes(): void
    {
        Session::flash('clip_render_old', [
            'clip_id' => 41,
            'start_time' => '1.250',
            'end_time' => '4.750',
            'aspect_ratio' => '9:16',
            'reframe_mode' => 'manual',
            'focus_x' => '0.250000',
            'focus_y' => '0.750000',
            'reframe_keyframes' => '"><svg/onload=alert(1)>',
        ]);
        $controller = new ProjectSuggestionController(
            new View(),
            static fn (int $projectId): array => self::project($projectId),
            static fn (): array => [self::clip(41, 'suggested')]
        );

        $html = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31'])->body();

        self::assertStringContainsString('option value="9:16" selected', $html);
        self::assertStringContainsString('option value="manual" selected', $html);
        self::assertStringContainsString('value="0.250000"', $html);
        self::assertStringContainsString('value="0.750000"', $html);
        self::assertStringContainsString('name="reframe_keyframes" type="hidden" value=""', $html);
        self::assertStringNotContainsString('&lt;svg/onload=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<svg/onload=alert(1)>', $html);
    }

    public function testOldCenterInputDropsIncompatibleFocusAndKeyframes(): void
    {
        Session::flash('clip_render_old', [
            'clip_id' => 41,
            'start_time' => '1.250',
            'end_time' => '4.750',
            'aspect_ratio' => '9:16',
            'reframe_mode' => 'center',
            'focus_x' => '0.250000',
            'focus_y' => '0.750000',
            'reframe_keyframes' => '[{"at_ms":0,"center_x":0.25,"center_y":0.5}]',
        ]);
        $controller = new ProjectSuggestionController(
            new View(),
            static fn (int $projectId): array => self::project($projectId),
            static fn (): array => [self::clip(41, 'suggested')]
        );

        $html = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31'])->body();

        self::assertStringContainsString('option value="center" selected', $html);
        self::assertStringContainsString('name="focus_x" type="number" min="0" max="1" step="0.000001" value=""', $html);
        self::assertStringContainsString('name="focus_y" type="number" min="0" max="1" step="0.000001" value=""', $html);
        self::assertStringContainsString('name="reframe_keyframes" type="hidden" value=""', $html);
    }

    public function testMainAndNoJsStylesKeepEditorResponsiveAccessibleAndOverflowSafe(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/projects.css');
        $noJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/projects-nojs.css');

        self::assertMatchesRegularExpression('/\.reframe-preview-shell[^}]*max-width:100%/s', $css);
        self::assertMatchesRegularExpression('/\.reframe-preview-stage[^}]*aspect-ratio:16\/9[^}]*position:relative/s', $css);
        self::assertMatchesRegularExpression('/\.reframe-overlay[^}]*inset:0[^}]*max-width:100%/s', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertMatchesRegularExpression('/\.reframe-editor[^}]*min-width:0/s', $css);
        self::assertMatchesRegularExpression('/\.reframe-controls[^}]*min-width:0/s', $css);
        self::assertMatchesRegularExpression('/\.reframe-controls[^}]*min-height:44px/s', $css);
        self::assertMatchesRegularExpression('/@media\s*\(max-width:600px\)[\s\S]*\.reframe-editor[^}]*grid-template-columns:1fr/s', $css);
        self::assertMatchesRegularExpression('/@media\s*\(max-width:600px\)[\s\S]*\.reframe-editor[^}]*\.button[^}]*width:100%/s', $css);
        self::assertMatchesRegularExpression('/@media\s*\(max-width:320px\)[\s\S]*overflow-wrap:anywhere/s', $css);
        self::assertMatchesRegularExpression('/@media\s*\(prefers-reduced-motion:reduce\)[\s\S]*(?:animation|transition):none/s', $css);
        self::assertStringContainsString('[data-reframe-preview-open][hidden]', $noJs);
        self::assertStringContainsString('[data-reframe-auto][hidden]', $noJs);
        self::assertStringContainsString('[data-reframe-overlay][hidden]', $noJs);
        self::assertStringNotContainsString('[name="focus_x"]', $noJs);
        self::assertStringNotContainsString('[name="focus_y"]', $noJs);
    }

    /** @param list<array<string,mixed>> $clips */
    private function render(array $clips, bool $consent): string
    {
        return (new View())->render('projects.show', [
            'title' => 'Projetos',
            'user' => ['id' => 17, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60],
            'project' => self::project(31),
            'clips' => $clips,
            'mediaPipeConsentActive' => $consent,
            'reframeUiConfig' => ['max_duration_ms' => 90000, 'max_frames' => 180, 'max_edge' => 320],
        ])->body();
    }

    /** @return array<string,mixed> */
    private function captureView(callable $controllerFactory): array
    {
        $root = sys_get_temp_dir() . '/reframe-view-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/projects', 0700, true));
        self::assertNotFalse(file_put_contents(
            $root . '/projects/show.php',
            '<?php echo json_encode(get_defined_vars(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);'
        ));

        try {
            $controller = $controllerFactory(new View($root));
            $response = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        } finally {
            @unlink($root . '/projects/show.php');
            @rmdir($root . '/projects');
            @rmdir($root);
        }

        return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function project(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Projeto reframe',
            'status' => 'suggestions_ready',
            'progress' => 100,
            'stage' => 'Sugestões prontas',
            'message' => 'Os cortes estão disponíveis.',
            'analysis_status' => 'completed',
            'video_summary' => null,
            'updated_at' => '2026-09-04 12:00:00',
        ];
    }

    /** @return array<string,mixed> */
    private static function clip(
        int $id,
        string $status,
        string $aspectRatio = 'original',
        string $mode = 'original'
    ): array {
        return [
            'id' => $id,
            'title' => 'Corte ' . $id,
            'start_time' => 1.25,
            'end_time' => 4.75,
            'duration_seconds' => 3.5,
            'viral_score' => 91,
            'hook' => 'Gancho',
            'reason' => 'Motivo',
            'category' => 'insight',
            'status' => $status,
            'render_start_time' => null,
            'render_end_time' => null,
            'output_aspect_ratio' => $aspectRatio,
            'reframe_mode' => $mode,
            'source_preview_url' => '/clips/' . $id . '/source-preview',
        ];
    }
}
