<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $input @param array<string, string> $headers */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $input = [],
        array $headers = [],
        private string $remoteAddress = '0.0.0.0',
        private string $rawBody = ''
    ) {
        $this->method = strtoupper($method);
        $this->path = self::normalizePath($path);
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /** @var array<string, string> */
    private array $headers;

    /** @param resource|null $bodyStream Optional already-open stream, owned by the caller. */
    public static function capture($bodyStream = null): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parts = parse_url($uri);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $query = $_GET;
        $input = $_POST;
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && (is_string($_SERVER['CONTENT_LENGTH']) || is_int($_SERVER['CONTENT_LENGTH']))) {
            $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        // One extra byte is retained so webhook validation can reject oversized input.
        // Never duplicate a large video upload in memory; PHP's parsed form/files remain intact.
        $ownsStream = $bodyStream === null;
        if ($ownsStream) $bodyStream = fopen('php://input', 'rb');
        if (!is_resource($bodyStream)) throw new \RuntimeException('Request body is unavailable.');
        try { $rawBody = stream_get_contents($bodyStream, 262145); }
        finally { if ($ownsStream) fclose($bodyStream); }
        if (!is_string($rawBody)) throw new \RuntimeException('Request body could not be read.');

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $query,
            $input,
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            is_string($rawBody) ? $rawBody : ''
        );
    }

    /** @param array<string, mixed> $input @param array<string, string> $headers */
    public static function fake(string $method, string $uri, array $input = [], array $headers = [], string $remoteAddress = '0.0.0.0'): self
    {
        $parts = parse_url($uri);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $query = [];

        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return new self($method, $path, $query, $input, $headers, $remoteAddress);
    }

    /** @param array<string, string> $headers */
    public static function fakeRaw(string $method, string $uri, string $rawBody, array $headers = [], string $remoteAddress = '0.0.0.0'): self
    {
        $request = self::fake($method, $uri, [], $headers, $remoteAddress);
        $request->rawBody = $rawBody;

        return $request;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function hasInput(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function clientIp(): string
    {
        return filter_var($this->remoteAddress, FILTER_VALIDATE_IP) !== false ? $this->remoteAddress : '0.0.0.0';
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
