<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PrivateStorage;
use App\Contracts\VideoAnalysisProvider;
use App\Gemini\GeminiException;
use App\Gemini\GeminiFile;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Gemini\OpenApiResponseSchema;
use App\Media\ProjectSource;
use Closure;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class GeminiService implements VideoAnalysisProvider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';
    private const FILE_NAME_PATTERN = '/\Afiles\/[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?\z/D';
    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'application/mp4',
        'video/quicktime',
        'video/webm',
    ];

    private GeminiTransport $transport;
    private PrivateStorage $storage;
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeoutSeconds;
    private int $responseLimitBytes;
    /** @var Closure(): float|int */
    private Closure $clock;

    public function __construct(
        GeminiTransport $transport,
        PrivateStorage $storage,
        string $apiKey,
        string $model,
        string $baseUrl,
        int $timeoutSeconds,
        int $responseLimitBytes,
        ?callable $monotonicClock = null
    ) {
        if ($baseUrl !== self::BASE_URL) {
            throw new InvalidArgumentException('Gemini base URL is invalid.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300 || $responseLimitBytes < 1 || $responseLimitBytes > 4194304) {
            throw new InvalidArgumentException('Gemini transport limits are invalid.');
        }

        $this->transport = $transport;
        $this->storage = $storage;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->responseLimitBytes = $responseLimitBytes;
        $this->clock = $monotonicClock === null
            ? static fn (): float => hrtime(true) / 1000000000
            : Closure::fromCallable($monotonicClock);
    }

    public function upload(ProjectSource $source): GeminiFile
    {
        $this->assertConfigured();
        if (!in_array($source->mimeType(), self::VIDEO_MIME_TYPES, true)) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        try {
            $absolutePath = $this->storage->absolutePath($source->objectKey());
        } catch (GeminiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw GeminiException::withCode('ai_unavailable');
        }
        clearstatcache(true, $absolutePath);
        $size = @filesize($absolutePath);
        if (!is_int($size) || $size < 1) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $deadline = $this->now() + $this->timeoutSeconds;
        $startBody = $this->encodeJson([
            'file' => ['displayName' => 'project-' . $source->projectId()],
        ]);
        $start = $this->callTransport(function () use ($source, $size, $startBody, $deadline): GeminiHttpResponse {
            return $this->transport->request(
                'POST',
                $this->baseUrl . '/upload/v1beta/files',
                $this->apiHeaders([
                    'X-Goog-Upload-Protocol' => 'resumable',
                    'X-Goog-Upload-Command' => 'start',
                    'X-Goog-Upload-Header-Content-Length' => (string) $size,
                    'X-Goog-Upload-Header-Content-Type' => $source->mimeType(),
                    'Content-Type' => 'application/json',
                ]),
                $startBody,
                $this->remainingSeconds($deadline),
                $this->responseLimitBytes
            );
        });
        $this->assertSuccessful($start);

        $uploadUrl = $start->header('x-goog-upload-url');
        if ($uploadUrl === null || !$this->isSafeUploadUrl($uploadUrl)) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $finished = $this->callTransport(function () use ($uploadUrl, $source, $absolutePath, $size, $deadline): GeminiHttpResponse {
            return $this->transport->upload(
                $uploadUrl,
                [
                    'Content-Type' => $source->mimeType(),
                    'Content-Length' => (string) $size,
                    'X-Goog-Upload-Offset' => '0',
                    'X-Goog-Upload-Command' => 'upload, finalize',
                ],
                $absolutePath,
                $size,
                $this->remainingSeconds($deadline),
                $this->responseLimitBytes
            );
        });
        $this->assertSuccessful($finished);

        return $this->parseFileResponse($finished, true, null);
    }

    public function getFile(string $resourceName): GeminiFile
    {
        $this->assertConfigured();
        $this->assertResourceName($resourceName);
        $response = $this->callTransport(function () use ($resourceName): GeminiHttpResponse {
            return $this->transport->request(
                'GET',
                $this->baseUrl . '/v1beta/' . $resourceName,
                $this->apiHeaders(),
                null,
                $this->timeoutSeconds,
                $this->responseLimitBytes
            );
        });
        $this->assertSuccessful($response);

        return $this->parseFileResponse($response, false, $resourceName);
    }

    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string
    {
        $this->assertConfigured();
        if ($file->state() !== 'ACTIVE' || trim($prompt) === '') {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $body = $this->encodeJson([
            'contents' => [[
                'parts' => [
                    ['fileData' => [
                        'mimeType' => $file->mimeType(),
                        'fileUri' => $file->uri(),
                    ]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => OpenApiResponseSchema::fromJsonSchema($responseSchema),
            ],
        ]);

        $response = $this->callTransport(function () use ($body): GeminiHttpResponse {
            return $this->transport->request(
                'POST',
                $this->baseUrl . '/v1beta/models/' . rawurlencode($this->model) . ':generateContent',
                $this->apiHeaders(['Content-Type' => 'application/json']),
                $body,
                $this->timeoutSeconds,
                $this->responseLimitBytes
            );
        });
        $this->assertSuccessful($response);

        return $this->extractFinalText($response->body());
    }

    public function deleteFile(string $resourceName): void
    {
        $this->assertConfigured();
        $this->assertResourceName($resourceName);
        $response = $this->callTransport(function () use ($resourceName): GeminiHttpResponse {
            return $this->transport->request(
                'DELETE',
                $this->baseUrl . '/v1beta/' . $resourceName,
                $this->apiHeaders(),
                null,
                $this->timeoutSeconds,
                $this->responseLimitBytes
            );
        });
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccessful($response);
    }

    private function assertConfigured(): void
    {
        if (!$this->isPrintableConfiguredValue($this->apiKey, 4096)
            || !$this->isPrintableConfiguredValue($this->model, 100)
        ) {
            throw GeminiException::withCode('ai_unconfigured');
        }
    }

    private function isPrintableConfiguredValue(string $value, int $maxLength): bool
    {
        return trim($value) !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[^\x20-\x7E]/', $value) !== 1;
    }

    private function assertResourceName(string $resourceName): void
    {
        if (preg_match(self::FILE_NAME_PATTERN, $resourceName) !== 1) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
    }

    /** @param array<string, string> $additional */
    private function apiHeaders(array $additional = []): array
    {
        return ['x-goog-api-key' => $this->apiKey] + $additional;
    }

    private function assertSuccessful(GeminiHttpResponse $response): void
    {
        if (strlen($response->body()) > $this->responseLimitBytes) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return;
        }
        throw GeminiException::fromHttpResponse($response);
    }

    private function parseFileResponse(
        GeminiHttpResponse $response,
        bool $wrapped,
        ?string $expectedResourceName
    ): GeminiFile {
        $decoded = $this->decodeObject($response->body());
        if ($wrapped) {
            if (!property_exists($decoded, 'file') || !$decoded->file instanceof stdClass) {
                throw GeminiException::withCode('ai_provider_rejected');
            }
            $decoded = $decoded->file;
        }

        foreach (['name', 'uri', 'mimeType'] as $property) {
            if (!property_exists($decoded, $property)
                || !is_string($decoded->{$property})
                || $decoded->{$property} === ''
            ) {
                throw GeminiException::withCode('ai_provider_rejected');
            }
        }
        $state = 'PROCESSING';
        if (property_exists($decoded, 'state') && $decoded->state !== null) {
            if (!is_string($decoded->state)) {
                throw GeminiException::withCode('ai_provider_rejected');
            }
            $state = $decoded->state === 'STATE_UNSPECIFIED' ? 'PROCESSING' : $decoded->state;
        }

        try {
            $file = new GeminiFile($decoded->name, $decoded->uri, $decoded->mimeType, $state);
        } catch (\Throwable $exception) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        if ($expectedResourceName !== null && $file->name() !== $expectedResourceName) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        return $file;
    }

    private function extractFinalText(string $body): string
    {
        $decoded = $this->decodeObject($body);
        if (property_exists($decoded, 'promptFeedback')
            && $decoded->promptFeedback instanceof stdClass
            && property_exists($decoded->promptFeedback, 'blockReason')
            && $decoded->promptFeedback->blockReason !== null
            && $decoded->promptFeedback->blockReason !== ''
        ) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        if (!property_exists($decoded, 'candidates') || !is_array($decoded->candidates)) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $usable = [];
        foreach ($decoded->candidates as $candidate) {
            if (!$candidate instanceof stdClass
                || !property_exists($candidate, 'finishReason')
                || !is_string($candidate->finishReason)
                || !property_exists($candidate, 'content')
                || !$candidate->content instanceof stdClass
                || !property_exists($candidate->content, 'parts')
                || !is_array($candidate->content->parts)
            ) {
                throw GeminiException::withCode('ai_provider_rejected');
            }
            if ($candidate->finishReason !== 'STOP') {
                continue;
            }

            $text = '';
            foreach ($candidate->content->parts as $part) {
                if (!$part instanceof stdClass) {
                    throw GeminiException::withCode('ai_provider_rejected');
                }
                if (property_exists($part, 'thought')) {
                    if (!is_bool($part->thought)) {
                        throw GeminiException::withCode('ai_provider_rejected');
                    }
                    if ($part->thought) {
                        continue;
                    }
                }
                if (property_exists($part, 'text')) {
                    if (!is_string($part->text)) {
                        throw GeminiException::withCode('ai_provider_rejected');
                    }
                    if (trim($part->text) !== '') {
                        $text .= $part->text;
                    }
                    continue;
                }
                if (property_exists($part, 'thoughtSignature') && is_string($part->thoughtSignature)) {
                    continue;
                }
                throw GeminiException::withCode('ai_provider_rejected');
            }
            if (trim($text) !== '') {
                $usable[] = $text;
            }
        }

        if (count($usable) !== 1) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        return $usable[0];
    }

    private function decodeObject(string $json): stdClass
    {
        if (strlen($json) > $this->responseLimitBytes) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        try {
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        if (!$decoded instanceof stdClass) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $value */
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

    private function callTransport(callable $operation): GeminiHttpResponse
    {
        try {
            $response = $operation();
        } catch (GeminiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw GeminiException::withCode('ai_unavailable');
        }
        if (!$response instanceof GeminiHttpResponse) {
            throw GeminiException::withCode('ai_unavailable');
        }

        return $response;
    }

    private function now(): float
    {
        try {
            $now = ($this->clock)();
        } catch (\Throwable $exception) {
            throw GeminiException::withCode('ai_unavailable');
        }
        if (!is_int($now) && !is_float($now)) {
            throw GeminiException::withCode('ai_unavailable');
        }
        $now = (float) $now;
        if (!is_finite($now)) {
            throw GeminiException::withCode('ai_unavailable');
        }

        return $now;
    }

    private function remainingSeconds(float $deadline): int
    {
        $remaining = $deadline - $this->now();
        if ($remaining <= 0) {
            throw GeminiException::withCode('ai_timeout');
        }

        return max(1, min($this->timeoutSeconds, (int) ceil($remaining)));
    }

    private function isSafeUploadUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'generativelanguage.googleapis.com'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443);
    }
}
