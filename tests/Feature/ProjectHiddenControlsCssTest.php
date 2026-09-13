<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class ProjectHiddenControlsCssTest extends TestCase
{
    public function testHiddenProjectControlsOverrideTheirFlexLayouts(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/projects-nojs.css');

        self::assertStringContainsString('[data-project-form] .source-tabs[hidden]', $css);
        self::assertStringContainsString('[data-project-form] .source-choice[hidden]', $css);
        self::assertStringContainsString('[data-project-form] .source-panel[hidden]', $css);
        self::assertStringContainsString('display:none!important', $css);
        self::assertGreaterThan(
            strpos($css, '.source-choice'),
            strpos($css, '[data-project-form] .source-choice[hidden]')
        );
        self::assertStringContainsString('[data-reframe-preview-open][hidden]', $css);
        self::assertStringContainsString('[data-reframe-auto][hidden]', $css);
        self::assertStringContainsString('[data-reframe-overlay][hidden]', $css);
        self::assertStringContainsString('[hidden]', $css);
        self::assertStringNotContainsString('[name="focus_x"]', $css);
        self::assertStringNotContainsString('[name="focus_y"]', $css);
    }
}
