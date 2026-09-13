<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AdminMiddleware;
use PHPUnit\Framework\TestCase;

final class AdminMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $_SESSION = [];
        $middleware = new AdminMiddleware(static fn (int $id): ?array => null);

        $response = $middleware->handle(Request::fake('GET', '/admin'), static fn (): Response => Response::text('private'));

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame('no-store, private', $response->header('Cache-Control'));
    }

    public function testActiveRegularUserReceivesForbidden(): void
    {
        $_SESSION = ['user_id' => 8];
        $middleware = new AdminMiddleware(static fn (int $id): ?array => [
            'id' => $id, 'name' => 'User', 'email' => 'u@example.test', 'role' => 'user', 'status' => 'active',
        ]);

        $response = $middleware->handle(Request::fake('GET', '/admin'), static fn (): Response => Response::text('private'));

        self::assertSame(403, $response->status());
        self::assertStringNotContainsString('private', $response->body());
    }

    public function testRoleIsRecheckedAndOnlyActiveAdminContinues(): void
    {
        $_SESSION = ['user_id' => 9];
        $lookups = 0;
        $middleware = new AdminMiddleware(static function (int $id) use (&$lookups): array {
            $lookups++;
            return ['id' => $id, 'name' => 'Admin', 'email' => 'a@example.test', 'role' => 'admin', 'status' => 'active'];
        });

        $response = $middleware->handle(Request::fake('GET', '/admin'), static fn (): Response => Response::text('allowed'));

        self::assertSame(200, $response->status());
        self::assertSame('allowed', $response->body());
        self::assertSame(1, $lookups);
        self::assertSame('no-store, private', $response->header('Cache-Control'));
    }

    public function testSuspendedAdminSessionIsCleared(): void
    {
        $_SESSION = ['user_id' => 9, '_csrf' => str_repeat('a', 64)];
        $middleware = new AdminMiddleware(static fn (int $id): array => [
            'id' => $id, 'name' => 'Admin', 'email' => 'a@example.test', 'role' => 'admin', 'status' => 'suspended',
        ]);

        $response = $middleware->handle(Request::fake('GET', '/admin'), static fn (): Response => Response::text('private'));

        self::assertSame(302, $response->status());
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertArrayNotHasKey('_csrf', $_SESSION);
    }
}
