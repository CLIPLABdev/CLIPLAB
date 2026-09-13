<?php
declare(strict_types=1);
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
final class LandingPresentationTest extends TestCase
{
    public function testLandingOffersRealSignupAndAccessibleStaticDemoAndFaq(): void
    {
        ob_start();
        require dirname(__DIR__, 2) . '/app/Views/home.php';
        $html = (string) ob_get_clean();
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $query = new \DOMXPath($dom);
        self::assertSame(1, $query->query('//h1')->length);
        self::assertGreaterThan(0, $query->query('//a[@href="/cadastro"]')->length);
        self::assertSame(3, $query->query('//*[@data-demo-stage]')->length);
        self::assertSame(1, $query->query('//*[@data-demo-stage and not(@hidden)]')->length);
        self::assertSame(0, $query->query('//*[@data-landing-demo]//form')->length);
        self::assertGreaterThanOrEqual(4, $query->query('//details/summary')->length);
    }

    public function testEmptyCatalogueDoesNotLeaveUnreachablePlansLink(): void
    {
        $publicPlans = [];
        ob_start();
        require dirname(__DIR__, 2) . '/app/Views/home.php';
        $html = (string) ob_get_clean();
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $query = new \DOMXPath($dom);
        self::assertSame(0, $query->query('//a[@href="#planos"]')->length);
    }
}
