<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;

final class SafePhase5TestDatabase
{
    private const DATABASE = 'clipforge_phase5_test';
    private const ERROR = 'TEST_DB_DSN must target only clipforge_phase5_test.';

    public static function validatedDsn(mixed $dsn): string
    {
        if (!is_string($dsn)
            || preg_match('/\Amysql:[^\r\n\x00]*\z/D', $dsn) !== 1
            || preg_match('/\s/', $dsn) === 1) {
            throw new InvalidArgumentException(self::ERROR);
        }

        $databaseTokens = 0;
        foreach (explode(';', substr($dsn, strlen('mysql:'))) as $attribute) {
            if ($attribute === '') {
                continue;
            }
            $separator = strpos($attribute, '=');
            if ($separator === false) {
                throw new InvalidArgumentException(self::ERROR);
            }
            $key = substr($attribute, 0, $separator);
            $value = substr($attribute, $separator + 1);
            if (strtolower(trim(rawurldecode($key))) !== 'dbname') {
                continue;
            }

            ++$databaseTokens;
            if ($key !== 'dbname' || $value !== self::DATABASE) {
                throw new InvalidArgumentException(self::ERROR);
            }
        }

        if ($databaseTokens !== 1) {
            throw new InvalidArgumentException(self::ERROR);
        }

        return $dsn;
    }

    public static function using(mixed $dsn, callable $consumer): mixed
    {
        return $consumer(self::validatedDsn($dsn));
    }
}
