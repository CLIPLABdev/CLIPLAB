<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testRejectsUnknownCsrfToken(): void
    {
        self::assertFalse(Csrf::validate('invalid'));
    }

    public function testIssuesTokenThatValidatesAndRotationInvalidatesTheOldValue(): void
    {
        $token = Csrf::token();

        self::assertTrue(Csrf::validate($token));

        Csrf::rotate();

        self::assertFalse(Csrf::validate($token));
    }

    public function testCsrfMiddlewareAllowsSafeRequests(): void
    {
        $response = (new CsrfMiddleware())->handle(Request::fake('GET', '/profile'), fn () => Response::text('profile'));

        self::assertSame(200, $response->status());
        self::assertSame('profile', $response->body());
    }

    public function testCsrfMiddlewareRejectsStateChangingRequestWithoutValidToken(): void
    {
        $response = (new CsrfMiddleware())->handle(Request::fake('POST', '/profile'), fn () => Response::text('updated'));

        self::assertSame(419, $response->status());
        self::assertStringContainsString('Sua sessão expirou', $response->body());
    }

    public function testCsrfMiddlewareAcceptsTokenFromRequestInput(): void
    {
        $response = (new CsrfMiddleware())->handle(
            Request::fake('POST', '/profile', ['_token' => Csrf::token()]),
            fn () => Response::text('updated')
        );

        self::assertSame('updated', $response->body());
    }

    public function testRouterProtectsPostsWithoutRouteLevelCsrfConfiguration(): void
    {
        $router = new Router();
        $router->post('/profile', fn () => Response::text('updated'));

        $blocked = $router->dispatch(Request::fake('POST', '/profile'));
        $allowed = $router->dispatch(Request::fake('POST', '/profile', ['_token' => Csrf::token()]));

        self::assertSame(419, $blocked->status());
        self::assertStringContainsString('Sua sessão expirou', $blocked->body());
        self::assertSame('updated', $allowed->body());
    }
}
