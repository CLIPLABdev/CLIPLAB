<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
final class SecurityHeadersOverrideTest extends TestCase
{
    public function testControllerCanApplyStricterPreviewAndTokenPrivacyPolicies(): void
    {
        $csp="default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox";
        $response=(new SecurityHeadersMiddleware())->handle(Request::fake('GET','/admin/emails/1/preview'),static fn()=>Response::html('preview')->withHeader('Content-Security-Policy',$csp)->withHeader('Referrer-Policy','no-referrer'));
        self::assertStringEndsWith(', '.$csp,$response->header('Content-Security-Policy'));
        self::assertStringContainsString("object-src 'none';",$response->header('Content-Security-Policy'));
        self::assertSame('no-referrer',$response->header('Referrer-Policy'));
        self::assertSame('nosniff',$response->header('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN',$response->header('X-Frame-Options'));
    }

    public function testResponseCannotLoosenGlobalSecurityOrReferrerPolicy(): void
    {
        $response=(new SecurityHeadersMiddleware())->handle(Request::fake('GET','/'),static fn()=>Response::html('test')->withHeader('Content-Security-Policy',"script-src * 'unsafe-inline'")->withHeader('Referrer-Policy','unsafe-url'));
        self::assertStringStartsWith("default-src 'self'; base-uri 'self'; object-src 'none';",$response->header('Content-Security-Policy'));
        self::assertStringContainsString("script-src 'self' https://cdn.jsdelivr.net;",$response->header('Content-Security-Policy'));
        self::assertSame('strict-origin-when-cross-origin',$response->header('Referrer-Policy'));
    }
}
