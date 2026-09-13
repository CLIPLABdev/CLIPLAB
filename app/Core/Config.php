<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string, array<mixed>> */
    private static array $files = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if ($file === null || $file === '') {
            return $default;
        }

        if (!array_key_exists($file, self::$files)) {
            $path = dirname(__DIR__, 2) . '/config/' . $file . '.php';
            self::$files[$file] = is_file($path) ? require $path : [];
        }

        $value = self::$files[$file];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
