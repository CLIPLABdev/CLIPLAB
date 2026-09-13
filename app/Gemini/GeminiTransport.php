<?php

declare(strict_types=1);

namespace App\Gemini;

interface GeminiTransport
{
    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse;

    /** @param array<string, string> $headers */
    public function upload(
        string $url,
        array $headers,
        string $absolutePath,
        int $sizeBytes,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse;
}
