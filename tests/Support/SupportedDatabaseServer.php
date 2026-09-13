<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;

final class SupportedDatabaseServer
{
    public static function identify(string $version, string $versionComment): string
    {
        $identity = $version . ' ' . $versionComment;
        if (preg_match('/\bmariadb\b/i', $identity) === 1) {
            return 'mariadb';
        }
        if (preg_match('/\bmysql\b/i', $identity) === 1) {
            return 'mysql';
        }

        throw new InvalidArgumentException('Unsupported test database engine.');
    }
}
