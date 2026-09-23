<?php

declare(strict_types=1);

namespace App\Gemini;

use InvalidArgumentException;

final class GeminiFile
{
    private const ALLOWED_MIME_TYPES = [
        'video/mp4',
        'application/mp4',
        'video/quicktime',
        'video/webm',
    ];

    private const ALLOWED_STATES = ['PROCESSING', 'ACTIVE', 'FAILED'];

    private string $name;
    private string $uri;
    private string $mimeType;
    private string $state;

    public function __construct(string $name, string $uri, string $mimeType, string $state)
    {
        if (preg_match('/\A(?:files\/[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?|file-[a-zA-Z0-9_-]{4,128}|[a-zA-Z0-9_-]{8,128})\z/D', $name) !== 1) {
            throw new InvalidArgumentException('Gemini file resource name is invalid.');
        }

        if (!$this->isProviderUri($uri)) {
            throw new InvalidArgumentException('Gemini file URI is invalid.');
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Gemini file MIME type is unsupported.');
        }

        if (!in_array($state, self::ALLOWED_STATES, true)) {
            throw new InvalidArgumentException('Gemini file state is invalid.');
        }

        $this->name = $name;
        $this->uri = $uri;
        $this->mimeType = $mimeType;
        $this->state = $state;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function state(): string
    {
        return $this->state;
    }

    private function isProviderUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 1024 || preg_match('/[\x00-\x20\x7F]/', $uri) !== 0) {
            return false;
        }

        $parts = parse_url($uri);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host === 'generativelanguage.googleapis.com'
            || str_ends_with($host, '.googleapis.com')
            || $host === 'api.openai.com'
            || str_ends_with($host, '.openai.com');
    }
}
