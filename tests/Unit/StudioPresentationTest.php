<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class StudioPresentationTest extends TestCase
{
    private function render(string $view, array $data): DOMXPath
    {
        $_SESSION = [];
        $html = (new View())->render($view, $data + [
            'title' => 'Visão geral',
            'user' => ['id' => 17, 'name' => 'Ana <Teste>', 'email' => 'ana@example.test', 'credits' => 4, 'plan_name' => 'Free', 'monthly_minutes' => 30, 'status' => 'active'],
        ])->body();
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($dom);
    }

    public function testUnknownProjectMetadataIsNotPresentedAsMeasuredZero(): void
    {
        $dom = $this->render('projects/index', ['created' => false, 'projects' => [
            ['id' => 19, 'name' => 'Em análise', 'status' => 'queued', 'duration_seconds' => null, 'size_bytes' => null],
            ['id' => 20, 'name' => 'Pronto', 'status' => 'completed', 'duration_seconds' => 125, 'size_bytes' => 1048576],
        ]]);
        self::assertSame('A confirmar', trim($dom->evaluate('string(//*[@data-project-id="19"]//dt[text()="Duração"]/../dd)')));
        self::assertSame('A confirmar', trim($dom->evaluate('string(//*[@data-project-id="19"]//dt[text()="Tamanho"]/../dd)')));
        self::assertSame('2:05', trim($dom->evaluate('string(//*[@data-project-id="20"]//dt[text()="Duração"]/../dd)')));
        self::assertSame('1,0 MB', trim($dom->evaluate('string(//*[@data-project-id="20"]//dt[text()="Tamanho"]/../dd)')));
        self::assertSame('/api/projects/19/status', $dom->evaluate('string(//*[@data-project-id="19"]/@data-project-status-url)'));
        self::assertSame('/projetos/20', $dom->evaluate('string(//*[@data-project-id="20"]//*[@data-project-suggestions-link]/@href)'));
    }

    public function testClipMetadataAndDownloadReflectAvailability(): void
    {
        $dom = $this->render('clips/index', ['library' => ['items' => [
            ['id' => 9, 'project_id' => 19, 'status' => 'rendering', 'display_duration_seconds' => null],
            ['id' => 10, 'project_id' => 19, 'status' => 'completed', 'display_duration_seconds' => 65, 'has_download' => true],
        ], 'filter' => 'processing', 'total' => 2, 'page' => 1, 'last_page' => 2]]);
        self::assertSame('A confirmar', trim($dom->evaluate('string(//*[@data-clip-card="9"]//dt[text()="Duração"]/../dd)')));
        self::assertSame('1:05', trim($dom->evaluate('string(//*[@data-clip-card="10"]//dt[text()="Duração"]/../dd)')));
        self::assertSame(0, $dom->query('//*[@data-clip-card="9"]//*[@data-clip-download]/@href')->length);
        self::assertSame('/clips/10/download', $dom->evaluate('string(//*[@data-clip-card="10"]//*[@data-clip-download]/@href)'));
        self::assertSame('/api/clips/9/status', $dom->evaluate('string(//*[@data-clip-card="9"]/@data-clip-status-url)'));
        self::assertSame('/clips?filter=processing&page=2', $dom->evaluate('string(//a[@rel="next"]/@href)'));
    }

    public function testIntakePreservesNativeControlsValuesAndErrorAnnouncement(): void
    {
        $dom = $this->render('projects/create', ['errors' => ['name' => 'Informe um nome.'], 'old' => ['source_type' => 'direct_url', 'name' => 'Minha aula', 'source_url' => 'https://example.test/video.mp4', 'auto_render_requested' => '0'], 'idempotencyKey' => 'fixture-key', 'maxUploadBytes' => 10485760]);
        self::assertSame('/projetos', $dom->evaluate('string(//form[@data-project-form]/@action)'));
        self::assertSame('multipart/form-data', $dom->evaluate('string(//form[@data-project-form]/@enctype)'));
        foreach (['_csrf', '_token', 'idempotency_key', 'MAX_FILE_SIZE', 'name', 'source_type', 'source_url', 'video_file', 'youtube_rights_confirmed', 'auto_render_requested'] as $name) {
            self::assertGreaterThan(0, $dom->query('//form[@data-project-form]//input[@name="' . $name . '"]')->length, $name);
        }
        self::assertSame('direct_url', $dom->evaluate('string(//input[@name="source_type"][@checked]/@value)'));
        self::assertSame('alert', $dom->evaluate('string(//*[@data-project-feedback]/@role)'));
        self::assertSame(0, $dom->query('//input[@name="auto_render_requested"][@type="checkbox"][@checked]')->length);
    }
}
