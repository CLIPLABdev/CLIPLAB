<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class SaasRouteCompositionTest extends TestCase
{
    public function testNewPrivateModulesAreRegisteredAndDenyGuestsBeforeOpeningDatabase(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        foreach (['/conta/plano', '/conta/creditos', '/admin', '/admin/jobs', '/clips/42/editar', '/clips/42/legendas.srt'] as $path) {
            $response = $router->dispatch(Request::fake('GET', $path));
            self::assertSame(302, $response->status(), $path);
            self::assertSame('/login', $response->header('Location'), $path);
        }
    }
}
