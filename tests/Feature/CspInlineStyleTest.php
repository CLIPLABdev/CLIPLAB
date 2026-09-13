<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Controllers\PasswordResetController;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

final class CspInlineStyleTest extends TestCase
{
    public function testRenderedAuthAndDashboardPagesContainNoInlineStyles(): void
    {
        $_SESSION = [];
        $view = new View();
        $auth = new AuthController($view, static fn () => throw new \LogicException(), static fn () => throw new \LogicException());
        $reset = new PasswordResetController($view, static fn () => throw new \LogicException());
        $responses = [$auth->showLogin(), $auth->showRegister(), $reset->showForgotPassword(), $reset->showResetPassword(Request::fake('GET', '/redefinir-senha?token=x'))];
        $responses[] = $view->render('dashboard.index', [
            'title' => 'Visão geral',
            'user' => ['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 10, 'plan_name' => 'Free', 'monthly_minutes' => 60, 'status' => 'active'],
            'metrics' => ['projects' => 0, 'processed' => 0, 'minutes' => 45, 'minutes_used' => 15, 'credits' => 10, 'storage_bytes' => 0, 'recent' => []],
        ]);
        foreach ($responses as $response) {
            self::assertDoesNotMatchRegularExpression('/<style(?:\s|>)/i', $response->body());
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $response->body());
        }
    }

    public function testCspContinuesToRejectInlineStyles(): void
    {
        $response = (new SecurityHeadersMiddleware())->handle(Request::fake('GET', '/'), static fn () => Response::html('ok'));
        self::assertStringContainsString("style-src 'self'", (string) $response->header('Content-Security-Policy'));
        self::assertStringNotContainsString("'unsafe-inline'", (string) $response->header('Content-Security-Policy'));
    }
}
