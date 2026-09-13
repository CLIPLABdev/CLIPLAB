<?php

declare(strict_types=1);

namespace App\Media;

final class DownloadResponse
{
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(private int $status, array $headers)
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException('Response headers must be strings.');
            }
            $normalized[strtolower($name)] = trim($value);
        }
        $this->headers = $normalized;
    }

    public function status(): int { return $this->status; }
    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }
    public function header(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
}
