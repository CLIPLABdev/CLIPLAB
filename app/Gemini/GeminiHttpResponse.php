<?php

declare(strict_types=1);

namespace App\Gemini;

use InvalidArgumentException;

final class GeminiHttpResponse
{
    private int $status;
    /** @var array<string, list<string>> */
    private array $headers;
    private string $body;

    /** @param array<string, string|list<string>> $headers */
    public function __construct(int $status, array $headers, string $body)
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP response status is invalid.');
        }

        $normalized = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name) || preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name) !== 1) {
                throw new InvalidArgumentException('HTTP response header is invalid.');
            }
            $list = is_string($values) ? [$values] : $values;
            if (!is_array($list) || $list === []) {
                throw new InvalidArgumentException('HTTP response header is invalid.');
            }
            $key = strtolower($name);
            foreach ($list as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('HTTP response header is invalid.');
                }
                $normalized[$key][] = trim($value, " \t");
            }
        }

        $this->status = $status;
        $this->headers = $normalized;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        $values = $this->headerValues($name);

        return count($values) === 1 ? $values[0] : null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function body(): string
    {
        return $this->body;
    }
}
