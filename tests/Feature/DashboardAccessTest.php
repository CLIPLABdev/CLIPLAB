<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class DashboardAccessTest extends TestCase
{
    public function testGuestsAreRedirectedFromDashboardAndProfile(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        $dashboard = $router->dispatch(Request::fake('GET', '/dashboard'));
        $profile = $router->dispatch(Request::fake('GET', '/perfil'));

        self::assertSame(302, $dashboard->status());
        self::assertSame('/login', $dashboard->header('Location'));
        self::assertSame(302, $profile->status());
        self::assertSame('/login', $profile->header('Location'));
    }

    public function testProfileMutationRequiresTheGlobalCsrfToken(): void
    {
        $_SESSION = ['user_id' => 17];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        $response = $router->dispatch(Request::fake('POST', '/perfil', ['name' => 'Ana', 'email' => 'ana@example.test']));

        self::assertSame(419, $response->status());
        self::assertStringContainsString('<title>Sessão expirada | ClipLab</title>', $response->body());
        self::assertStringContainsString('<h1>Sua sessão expirou</h1>', $response->body());
        self::assertStringContainsString('Atualize a página e envie o formulário novamente.', $response->body());
        self::assertStringNotContainsString('CSRF token mismatch.', $response->body());
    }
}
