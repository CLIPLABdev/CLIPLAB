<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = (string) Config::get('database.dsn');
        $username = Config::get('database.username');
        $password = Config::get('database.password');

        self::$connection = new PDO(
            $dsn,
            is_string($username) ? $username : null,
            is_string($password) ? $password : null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        if (self::$connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            self::$connection->exec("SET time_zone = '+00:00'");
        }

        return self::$connection;
    }
}
