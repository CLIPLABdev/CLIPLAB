<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class PasswordResetRoutesTest extends TestCase
{
    public function testPasswordResetGetRoutesRenderWithoutOpeningTheDatabase(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        self::assertInstanceOf(Router::class, $router);
        self::assertSame(200, $router->dispatch(Request::fake('GET', '/esqueci-minha-senha'))->status());
        self::assertSame(200, $router->dispatch(Request::fake('GET', '/redefinir-senha?token=example-token'))->status());
    }

    public function testPasswordResetSubmissionIsCoveredByGlobalCsrf(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        self::assertSame(419, $router->dispatch(Request::fake('POST', '/esqueci-minha-senha', ['email' => 'person@example.test']))->status());
    }
}
