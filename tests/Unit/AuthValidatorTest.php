<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\AuthValidator;
use PHPUnit\Framework\TestCase;

final class AuthValidatorTest extends TestCase
{
    public function testRejectsWeakRegistration(): void
    {
        $errors = AuthValidator::registration([
            'name' => 'A',
            'email' => 'x',
            'password' => '123',
            'password_confirmation' => '321',
        ]);

        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('password', $errors);
    }

    public function testAcceptsACompleteRegistration(): void
    {
        $errors = AuthValidator::registration([
            'name' => 'Ana Silva',
            'email' => 'ANA@example.com',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]);

        self::assertSame([], $errors);
    }
}
