<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class UploadTooLargeResponseTest extends TestCase
{
    public function testOversizedPostExplainsTheLimitAndOffersARecoverableNavigation(): void
    {
        $response = (new Router(null, 1024))->dispatch(Request::fake('POST', '/projetos', [], ['Content-Length' => '1025']));
        self::assertSame(413, $response->status());
        self::assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->body());
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//a[@href="/projetos/novo"]')->length);
        self::assertStringContainsString('limite', mb_strtolower($response->body()));
    }
}
