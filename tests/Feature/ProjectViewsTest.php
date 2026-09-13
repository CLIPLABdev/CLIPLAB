<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\View;
use App\Media\ProjectReceipt;
use PHPUnit\Framework\TestCase;

final class ProjectViewsTest extends TestCase
{
    protected function setUp(): void { $_SESSION = ['user_id' => 9]; }
    protected function tearDown(): void { $_SESSION = []; }

    public function testCreationFormIsAccessibleAndSupportsBothServerRenderedModes(): void
    {
        $html = $this->controller()->create()->body();
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="idempotency_key"', $html);
        self::assertStringContainsString('name="video_file"', $html);
        self::assertStringContainsString('name="source_url"', $html);
        self::assertStringContainsString('accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"', $html);
        self::assertStringContainsString('data-source-tabs', $html);
        self::assertStringContainsString('data-source-panel="upload"', $html);
        self::assertStringContainsString('data-source-panel="direct_url"', $html);
        self::assertStringContainsString('/assets/css/projects.css', $html);
        self::assertStringContainsString('500 MB', $html);
    }

    public function testLibraryRendersHonestEmptyStateAndEscapedProjectCards(): void
    {
        $rows = [['id' => 15, 'name' => '<script>alert(1)</script>', 'source_type' => 'direct_url', 'duration_seconds' => 126, 'size_bytes' => 1048576, 'created_at' => '2026-09-03 10:00:00', 'status' => 'queued', 'progress' => 20]];
        $controller = new ProjectController(new View(), new ViewFakeProjectCreator(), static fn (): array => $rows);
        $html = $controller->index()->body();
        self::assertStringContainsString('data-project-id="15"', $html);
        self::assertStringContainsString('data-project-status-url="/api/projects/15/status"', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Importação por URL', $html);
        self::assertStringContainsString('2:06', $html);
        self::assertStringContainsString('1,0 MB', $html);
    }

    public function testEmptyLibraryOffersTheCreationAction(): void
    {
        $html = $this->controller()->index()->body();
        self::assertStringContainsString('Nenhum projeto por enquanto', $html);
        self::assertStringContainsString('href="/projetos/novo"', $html);
    }

    private function controller(): ProjectController { return new ProjectController(new View(), new ViewFakeProjectCreator(), static fn (): array => []); }
}

final class ViewFakeProjectCreator implements ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
    public function fromDirectUrl(int $userId, array $input): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
}
