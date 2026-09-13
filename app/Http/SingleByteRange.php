<?php

declare(strict_types=1);

namespace App\Http;

final class SingleByteRange
{
    private function __construct(
        private int $start,
        private int $end,
        private int $totalBytes
    ) {
    }

    public static function fromHeader(?string $header, int $totalBytes): ?self
    {
        if ($totalBytes < 1) {
            throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
        }
        if ($header === null) {
            return null;
        }
        if (preg_match('/\Abytes=(\d*)-(\d*)\z/D', $header, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')
        ) {
            throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
        }

        if ($matches[1] === '') {
            $suffixLength = self::unsignedInteger($matches[2]);
            if ($suffixLength < 1) {
                throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
            }
            $length = min($suffixLength, $totalBytes);

            return new self($totalBytes - $length, $totalBytes - 1, $totalBytes);
        }

        $start = self::unsignedInteger($matches[1]);
        if ($start >= $totalBytes) {
            throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
        }
        if ($matches[2] === '') {
            return new self($start, $totalBytes - 1, $totalBytes);
        }

        $requestedEnd = self::unsignedInteger($matches[2]);
        if ($requestedEnd < $start) {
            throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
        }

        return new self($start, min($requestedEnd, $totalBytes - 1), $totalBytes);
    }

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->end;
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    public function contentRange(): string
    {
        return 'bytes ' . $this->start . '-' . $this->end . '/' . $this->totalBytes;
    }

    private static function unsignedInteger(string $raw): int
    {
        $maximum = (string) PHP_INT_MAX;
        if ($raw === ''
            || strlen($raw) > strlen($maximum)
            || (strlen($raw) === strlen($maximum) && strcmp($raw, $maximum) > 0)
        ) {
            throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
        }
        $value = (int) $raw;
        if ((string) $value !== $raw) {
            throw new UnsatisfiableByteRange('The byte range is unsatisfiable.');
        }

        return $value;
    }
}
