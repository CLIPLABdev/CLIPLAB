<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class PrivateMediaRoot
{
    public static function resolve(?string $configured, string $projectRoot, string $publicRoot): string
    {
        $candidate = trim((string) $configured);
        if ($candidate === '') {
            $candidate = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media';
        } elseif (!self::isAbsolute($candidate)) {
            $candidate = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $candidate;
        }

        $candidate = self::canonicalize($candidate);
        $publicRoot = self::canonicalize($publicRoot);
        $comparableCandidate = self::comparable($candidate);
        $comparablePublic = rtrim(self::comparable($publicRoot), DIRECTORY_SEPARATOR);
        $segments = preg_split('/[\\\\\/]+/', $comparableCandidate) ?: [];

        if ($comparableCandidate === $comparablePublic
            || str_starts_with($comparableCandidate, $comparablePublic . DIRECTORY_SEPARATOR)
            || in_array('public_html', $segments, true)) {
            throw new InvalidArgumentException('MEDIA_PRIVATE_ROOT must remain outside the public directory.');
        }

        return $candidate;
    }

    private static function canonicalize(string $path): string
    {
        $path = self::normalize($path);
        $probe = $path;
        $tail = [];

        while (!file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return $path;
            }
            array_unshift($tail, basename($probe));
            $probe = $parent;
        }

        $resolved = realpath($probe);
        if ($resolved === false) {
            return $path;
        }

        return self::normalize($resolved . ($tail === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $tail)));
    }

    private static function normalize(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if (!self::isAbsolute($path)) {
            throw new InvalidArgumentException('Media paths must resolve to an absolute location.');
        }

        $prefix = DIRECTORY_SEPARATOR;
        $remainder = ltrim($path, DIRECTORY_SEPARATOR);
        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            $prefix = strtoupper(substr($path, 0, 2)) . DIRECTORY_SEPARATOR;
            $remainder = substr($path, 3);
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === []
            ? $prefix
            : rtrim($prefix . implode(DIRECTORY_SEPARATOR, $segments), DIRECTORY_SEPARATOR);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function comparable(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
