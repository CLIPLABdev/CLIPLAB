<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PrivateStorage;
use App\Contracts\VideoAnalysisProvider;
use App\Gemini\GeminiException;
use App\Gemini\GeminiFile;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Media\ProjectSource;
use InvalidArgumentException;
use JsonException;

final class OpenAiVideoAnalysisService implements VideoAnalysisProvider
{
    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'application/mp4',
        'video/quicktime',
        'video/webm',
    ];

    public function __construct(
        private GeminiTransport $transport,
        private PrivateStorage $storage,
        private string $apiKey,
        private string $model,
        private string $baseUrl,
        private int $timeoutSeconds,
        private int $responseLimitBytes
    ) {
        if (!is_string($baseUrl) || trim($baseUrl) === '' || preg_match('/\Ahttps:\/\/[A-Za-z0-9.-]+(?:\/[A-Za-z0-9._\-]+)*\/?\z/i', $baseUrl) !== 1) {
            throw new InvalidArgumentException('OpenAI base URL is invalid.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300 || $responseLimitBytes < 1 || $responseLimitBytes > 4194304) {
            throw new InvalidArgumentException('OpenAI transport limits are invalid.');
        }
    }

    public function upload(ProjectSource $source): GeminiFile
    {
        $this->assertConfigured();
        if (!in_array($source->mimeType(), self::VIDEO_MIME_TYPES, true)) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        try {
            $absolutePath = $this->storage->absolutePath($source->objectKey());
        } catch (\Throwable) {
            throw GeminiException::withCode('ai_unavailable');
        }

        clearstatcache(true, $absolutePath);
        $size = @filesize($absolutePath);
        if (!is_int($size) || $size < 1) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $resourceName = 'file-' . substr(hash('sha256', $source->objectKey()), 0, 32);
        $uri = rtrim($this->baseUrl, '/') . '/files/' . $resourceName;

        return new GeminiFile('files/' . $resourceName, $uri, $source->mimeType(), 'ACTIVE');
    }

    public function getFile(string $resourceName): GeminiFile
    {
        $this->assertConfigured();
        $normalized = $this->normalizeFileName($resourceName);
        $uri = rtrim($this->baseUrl, '/') . '/files/' . $normalized;

        return new GeminiFile($normalized, $uri, 'video/mp4', 'ACTIVE');
    }

    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string
    {
        $this->assertConfigured();
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'clip_analysis',
                    'schema' => $responseSchema,
                    'strict' => true,
                ],
            ],
        ];

        $body = $this->encodeJson($payload);
        $response = $this->transport->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/chat/completions',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            $body,
            $this->timeoutSeconds,
            $this->responseLimitBytes
        );

        if ($response->status() >= 200 && $response->status() < 300) {
            return $this->extractContent($response->body());
        }

        throw GeminiException::fromHttpResponse($response);
    }

    public function deleteFile(string $resourceName): void
    {
        $this->assertConfigured();
        $this->normalizeFileName($resourceName);
    }

    private function assertConfigured(): void
    {
        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            throw GeminiException::withCode('ai_unconfigured');
        }
    }

    private function normalizeFileName(string $resourceName): string
    {
        if ($resourceName === '' || !preg_match('/\A(?:files\/[A-Za-z0-9._-]+|file-[A-Za-z0-9._-]+)\z/', $resourceName)) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        return ltrim($resourceName, '/');
    }

    private function encodeJson(array $value): string
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        if (strlen($json) > $this->responseLimitBytes) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        return $json;
    }

    private function extractContent(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $message = $choices[0]['message'] ?? null;
        if (!is_array($message)) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $content = $message['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        return $content;
    }
}
