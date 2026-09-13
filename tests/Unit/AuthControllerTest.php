<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    public function testRendersLoginAndRegistrationFormsWithCsrfProtection(): void
    {
        $_SESSION = [];
        $controller = new AuthController(new View(), static fn () => throw new \LogicException('Not used by a GET request.'), static fn () => throw new \LogicException('Not used by a GET request.'));

        $login = $controller->showLogin();
        $register = $controller->showRegister();

        self::assertSame(200, $login->status());
        self::assertStringContainsString('action="/login"', $login->body());
        self::assertStringContainsString('name="_token"', $login->body());
        self::assertSame(200, $register->status());
        self::assertStringContainsString('action="/cadastro"', $register->body());
        self::assertStringContainsString('name="password_confirmation"', $register->body());
        self::assertStringContainsString('name="_token"', $register->body());
    }
}
