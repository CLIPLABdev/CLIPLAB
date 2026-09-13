<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class ClipLibraryViewTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['_csrf' => str_repeat('a', 64), 'user_id' => 17];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRendersRealFiltersCardsPaginationAndEscapesEveryVariable(): void
    {
        $queued = $this->clip(41, 'queued');
        $queued['title'] = 'Corte <script>alert(1)</script>';
        $queued['project_name'] = 'Projeto & "principal"';
        $queued['source_name'] = 'episódio <final>.mp4';
        $queued['hook'] = '<img src=x onerror=alert(2)>';
        $queued['reason'] = 'Motivo <forte>';
        $queued['category'] = 'educação & negócios';
        $queued['object_key'] = 'private/never-render-this.mp4';
        $completed = $this->clip(42, 'completed');
        $completed['has_thumbnail'] = true;
        $completed['has_download'] = true;

        $html = $this->render([$queued, $completed], [
            'filter' => 'recent',
            'page' => 2,
            'per_page' => 24,
            'total' => 26,
            'last_page' => 2,
        ]);

        foreach (['recent', 'processing', 'completed', 'failed'] as $filter) {
            self::assertStringContainsString('/clips?filter=' . $filter, $html);
        }
        self::assertStringContainsString('26 clipes', $html);
        self::assertStringContainsString('data-clip-card="41"', $html);
        self::assertStringContainsString('data-clip-status-url="/api/clips/41/status"', $html);
        self::assertStringContainsString('data-clip-status', $html);
        self::assertStringContainsString('data-clip-message', $html);
        self::assertStringContainsString('data-clip-thumbnail', $html);
        self::assertStringContainsString('data-clip-download', $html);
        self::assertStringContainsString('src="/clips/42/thumbnail"', $html);
        self::assertStringContainsString('href="/clips/42/download"', $html);
        self::assertStringContainsString('href="/projetos/731"', $html);
        self::assertStringContainsString('/assets/js/clip-status.js', $html);
        self::assertStringContainsString('A pontuação é uma estimativa da IA e não garante viralização.', $html);
        self::assertStringContainsString('0:24', $html);
        self::assertStringContainsString('06/09/2026 às 14:30', $html);
        self::assertStringContainsString('92', $html);
        self::assertStringContainsString('Projeto &amp; &quot;principal&quot;', $html);
        self::assertStringContainsString('episódio &lt;final&gt;.mp4', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('private/never-render-this.mp4', $html);
        self::assertStringNotContainsString('<video', strtolower($html));
        self::assertStringContainsString('href="/clips?filter=recent&amp;page=1"', $html);
        self::assertStringNotContainsString('page=3', $html);

        $xpath = $this->xpath($html);
        $active = $xpath->query('//*[@data-clip-card="41"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $active);
        self::assertSame(1, $xpath->query('.//*[@data-clip-status]', $active)->length);
        self::assertSame(1, $xpath->query('.//*[@data-clip-message]', $active)->length);
        self::assertSame(1, $xpath->query('.//*[@data-clip-thumbnail and @hidden and not(@src)]', $active)->length);
        self::assertSame(1, $xpath->query('.//*[@data-clip-download and @hidden and not(@href)]', $active)->length);
    }

    public function testEmptyCompletedFilterUsesRealActionsAndDoesNotStartPolling(): void
    {
        $html = $this->render([], [
            'filter' => 'completed',
            'page' => 1,
            'per_page' => 24,
            'total' => 0,
            'last_page' => 1,
        ]);

        self::assertStringContainsString('Nenhum clipe concluído', $html);
        self::assertStringContainsString('0 clipes', $html);
        self::assertStringContainsString('href="/projetos/novo"', $html);
        self::assertStringContainsString('href="/projetos"', $html);
        self::assertStringNotContainsString('/assets/js/clip-status.js', $html);
        self::assertStringNotContainsString('data-clip-card', $html);
        self::assertStringNotContainsString('<video', strtolower($html));
    }

    public function testCompletedCardWithoutAssetFlagsKeepsPrivateAssetsUnavailable(): void
    {
        $html = $this->render([$this->clip(51, 'completed')]);
        $xpath = $this->xpath($html);
        $card = $xpath->query('//*[@data-clip-card="51"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $card);

        self::assertSame(1, $xpath->query('.//*[@data-clip-thumbnail and @hidden and not(@src)]', $card)->length);
        self::assertSame(1, $xpath->query('.//*[@data-clip-download and @hidden and not(@href)]', $card)->length);
        self::assertSame(0, $xpath->query('.//video', $card)->length);
    }

    public function testStylesKeepHiddenAssetsResponsiveAndKeyboardFocusVisible(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/clips.css');

        self::assertMatchesRegularExpression('/\.clip-library\s+\[hidden\]\s*\{[^}]*display:none\s*!important/s', $css);
        self::assertMatchesRegularExpression('/\.clip-library-grid\s*\{[^}]*grid-template-columns:[^;}]*minmax/s', $css);
        self::assertMatchesRegularExpression('/\.clip-library-card\s*\{[^}]*min-width:0/s', $css);
        self::assertMatchesRegularExpression('/\.clip-copy[^}]*overflow-wrap:anywhere/s', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertMatchesRegularExpression('/@media\s*\(max-width:768px\)[\s\S]*grid-template-columns:1fr/s', $css);
        self::assertMatchesRegularExpression('/@media\s*\(max-width:420px\)[\s\S]*overflow-wrap:anywhere/s', $css);
        self::assertMatchesRegularExpression('/@media\s*\(prefers-reduced-motion:reduce\)[\s\S]*transition:none/s', $css);
    }

    /** @param list<array<string,mixed>> $items @param array<string,int|string> $overrides */
    private function render(array $items, array $overrides = []): string
    {
        return (new View())->render('clips.index', [
            'title' => 'Clipes',
            'user' => self::user(),
            'library' => array_merge([
                'items' => $items,
                'filter' => 'recent',
                'page' => 1,
                'per_page' => 24,
                'total' => count($items),
                'last_page' => 1,
            ], $overrides),
        ])->body();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    /** @return array<string,mixed> */
    private static function clip(int $id, string $status): array
    {
        return [
            'id' => $id,
            'project_id' => 731,
            'project_name' => 'Projeto Aurora',
            'source_type' => 'upload',
            'source_name' => 'episodio-01.mp4',
            'title' => 'Três decisões que mudaram o resultado',
            'status' => $status,
            'display_duration_seconds' => 24.4,
            'viral_score' => 92,
            'hook' => 'A terceira decisão muda toda a leitura.',
            'reason' => 'Começa com tensão e entrega uma conclusão objetiva.',
            'category' => 'insight',
            'output_aspect_ratio' => '9:16',
            'reframe_mode' => 'auto',
            'created_at' => '2026-09-06 14:00:00',
            'updated_at' => '2026-09-06 14:30:00',
            'rendered_at' => $status === 'completed' ? '2026-09-06 14:29:00' : null,
            'has_thumbnail' => false,
            'has_download' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function user(): array
    {
        return [
            'id' => 17,
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'credits' => 7,
            'plan_name' => 'Free',
            'monthly_minutes' => 60,
            'status' => 'active',
        ];
    }
}
