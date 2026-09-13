<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\View;
use App\Media\ProjectReceipt;
use PHPUnit\Framework\TestCase;

final class ProjectWorkflowPresentationTest extends TestCase
{
    protected function setUp(): void { $_SESSION = ['user_id' => 51]; }
    protected function tearDown(): void { $_SESSION = []; }

    public function testDashboardAndEmptyLibraryLinkToProjectCreation(): void
    {
        $dashboard = (new View())->render('dashboard.index', [
            'title' => 'Visão geral',
            'user' => ['id' => 51, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 10, 'plan_name' => 'Free', 'monthly_minutes' => 60, 'status' => 'active'],
            'metrics' => ['projects' => 0, 'processed' => 0, 'minutes' => 60, 'minutes_used' => 0, 'credits' => 10, 'storage_bytes' => 0, 'recent' => []],
        ]);
        $library = new ProjectController(new View(), new PresentationProjectCreator(), static fn (): array => []);

        self::assertStringContainsString('href="/projetos/novo"', $dashboard->body());
        self::assertStringContainsString('href="/projetos/novo"', $library->index()->body());
    }

    public function testLibraryHasAccessibleStatusTargetsAndLoadsBoundedPolling(): void
    {
        $rows = [['id' => 52, 'name' => 'Entrevista', 'source_type' => 'upload', 'duration_seconds' => null, 'size_bytes' => 4096, 'created_at' => '2026-09-03 12:00:00', 'status' => 'probing', 'progress' => 70]];
        $library = new ProjectController(new View(), new PresentationProjectCreator(), static fn (): array => $rows);
        $html = $library->index()->body();

        self::assertStringContainsString('data-project-status-url="/api/projects/52/status"', $html);
        self::assertStringContainsString('data-project-status-text', $html);
        self::assertStringContainsString('data-project-progress-value', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('/assets/js/project-status.js', $html);
        self::assertStringContainsString('href="/projetos"', $html);
    }
    public function testTerminalOnlyLibraryDoesNotLoadPollingAsset(): void
    {
        $rows = [['id' => 53, 'name' => 'Pronto', 'source_type' => 'upload', 'duration_seconds' => 91, 'size_bytes' => 4096, 'created_at' => '2026-09-03 12:00:00', 'status' => 'ready', 'progress' => 100]];
        $library = new ProjectController(new View(), new PresentationProjectCreator(), static fn (): array => $rows);

        self::assertStringNotContainsString('/assets/js/project-status.js', $library->index()->body());
    }}

final class PresentationProjectCreator implements ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
    public function fromDirectUrl(int $userId, array $input): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
}