<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        Session::start();

        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function rotate(): string
    {
        Session::start();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));

        return $_SESSION['_csrf'];
    }

    public static function validate(?string $token): bool
    {
        Session::start();
        $stored = $_SESSION['_csrf'] ?? '';

        return is_string($token) && is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }
}
