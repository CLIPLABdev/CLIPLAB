<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\View;
use App\Media\ProjectReceipt;
use PHPUnit\Framework\TestCase;

final class ProjectProgressiveEnhancementTest extends TestCase
{
    public function testCreationMarkupUsesFunctionalControlsBeforeJavascriptLoads(): void
    {
        $_SESSION = ['user_id' => 8];
        $html = (new ProjectController(new View(), new ProgressiveEnhancementCreator(), static fn (): array => []))->create()->body();

        self::assertStringContainsString('class="source-choice"', $html);
        self::assertStringContainsString('name="source_type" value="upload"', $html);
        self::assertStringContainsString('name="source_type" value="direct_url"', $html);
        self::assertStringNotContainsString('role="tablist"', $html);
        self::assertStringNotContainsString('role="tabpanel"', $html);
        self::assertStringContainsString('<script src="/assets/js/projects.js" defer></script>', $html);
    }

    public function testProjectScriptPromotesTheFallbackIntoAccessibleTabs(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/projects.js');

        self::assertStringContainsString("tabList.setAttribute('role', 'tablist')", $script);
        self::assertStringContainsString("tab.setAttribute('role', 'tab')", $script);
        self::assertStringContainsString("panel.setAttribute('role', 'tabpanel')", $script);
        self::assertStringContainsString('tabList.hidden = false', $script);
        self::assertStringContainsString('sourceChoice.hidden = true', $script);
    }
}

final class ProgressiveEnhancementCreator implements ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
    public function fromDirectUrl(int $userId, array $input): ProjectReceipt { return new ProjectReceipt(1, 'queued', true); }
}
