<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class HomePageTest extends TestCase
{
    public function testLandingContainsPrimaryJourney(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        $response = $router->dispatch(Request::fake('GET', '/'));

        self::assertSame(200, $response->status());
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $response->body());
        self::assertSame(1, $dom->getElementsByTagName('h1')->length);
        self::assertStringContainsString('/cadastro', $response->body());
        self::assertStringContainsString('Como funciona', $response->body());
    }
}
