<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var array<string, array{name: string, value: string}> */
    private array $headers = [];

    private ?\Closure $emitter;

    public function __construct(private string $body = '', private int $status = 200, ?\Closure $emitter = null)
    {
        $this->emitter = $emitter;
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return (new self(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status))
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public static function redirect(string $url): self
    {
        return (new self('', 302))->withHeader('Location', $url);
    }

    public static function stream(callable $emitter, int $status = 200): self
    {
        return new self('', $status, \Closure::fromCallable($emitter));
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)]['value'] ?? $default;
    }

    public function withHeader(string $name, string $value): self
    {
        $response = clone $this;
        $response->headers[strtolower($name)] = ['name' => $name, 'value' => $value];

        return $response;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $header) {
                header($header['name'] . ': ' . $header['value'], true);
            }
        }

        if ($this->emitter !== null) {
            ($this->emitter)();

            return;
        }

        echo $this->body;
    }
}
