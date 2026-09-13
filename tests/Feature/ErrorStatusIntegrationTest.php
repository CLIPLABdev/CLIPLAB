<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ErrorHandler;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

final class ErrorStatusIntegrationTest extends TestCase
{
    private string $directory;
    private string $logFile;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->directory = sys_get_temp_dir() . '/clipforge-error-status-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->directory));
        $this->logFile = $this->directory . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testRouterUsesTheDedicatedNotFoundPage(): void
    {
        $router = new Router(new ErrorHandler(new Logger($this->logFile)));

        $response = $router->dispatch(Request::fake('GET', '/missing'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Página não encontrada', $response->body());
    }

    public function testCsrfMiddlewareUsesTheDedicatedExpiredSessionPage(): void
    {
        Session::start();
        $middleware = new CsrfMiddleware(new ErrorHandler(new Logger($this->logFile)));

        $response = $middleware->handle(Request::fake('POST', '/login'), fn (): Response => Response::text('never'));

        self::assertSame(419, $response->status());
        self::assertStringContainsString('Sua sessão expirou', $response->body());
    }
}
