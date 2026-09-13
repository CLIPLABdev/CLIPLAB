<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class AppLayoutAccessibilityTest extends TestCase
{
    public function testMobileDrawerContractRemovesClosedNavigationFromTheAccessibilityTree(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
        $script = (string) file_get_contents($root . '/public/assets/js/dashboard.js');

        self::assertStringContainsString('data-app-sidebar', $layout);
        self::assertStringContainsString('matchMedia', $script);
        self::assertStringContainsString('sidebar.inert', $script);
        self::assertStringContainsString("setAttribute('aria-hidden'", $script);
    }

    public function testClipsNavigationIsKeyboardReachableAndMarksTheCurrentPage(): void
    {
        $title = 'Clipes';
        $content = '<p>Conteúdo da biblioteca</p>';
        $user = [
            'id' => 17,
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'credits' => 7,
            'plan_name' => 'Free',
            'monthly_minutes' => 60,
        ];
        $_SESSION = ['_csrf' => str_repeat('a', 64), 'user_id' => 17];
        ob_start();
        require dirname(__DIR__, 2) . '/app/Views/layouts/app.php';
        $html = (string) ob_get_clean();
        $_SESSION = [];

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $link = $xpath->query('//nav[@aria-label="Principal"]/a[@href="/clips"]')->item(0);

        self::assertInstanceOf(DOMElement::class, $link);
        self::assertSame('page', $link->getAttribute('aria-current'));
        self::assertFalse($link->hasAttribute('tabindex'));
        self::assertSame(1, $xpath->query('.//*[@data-lucide="scissors" and @aria-hidden="true"]', $link)->length);
    }
}
