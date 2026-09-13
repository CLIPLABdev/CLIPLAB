<?php

declare(strict_types=1);

namespace App\Validation;

final class AuthValidator
{
    /** @param array<string, mixed> $input @return array<string, string> */
    public static function registration(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Informe um nome válido.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['email'] = 'Informe um e-mail válido.';
        }

        if (strlen($password) < 12 || $password !== $confirmation) {
            $errors['password'] = 'A senha deve ter ao menos 12 caracteres e coincidir com a confirmação.';
        }

        return $errors;
    }
}
