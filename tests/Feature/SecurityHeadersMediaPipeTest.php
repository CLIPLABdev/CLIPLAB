<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersMediaPipeTest extends TestCase
{
    public function testCspAllowsOnlySameOriginWorkersWithoutEvalOrBlob(): void
    {
        $response = (new SecurityHeadersMiddleware())->handle(Request::fake('GET', '/'), static fn () => Response::html('ok'));
        $csp = (string) $response->header('Content-Security-Policy');
        self::assertStringContainsString("worker-src 'self'", $csp);
        self::assertStringNotContainsString("'unsafe-eval'", $csp);
        self::assertStringNotContainsString('blob:', $csp);
    }
}
