<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Gemini\GeminiFile;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Media\StoredObject;
use App\Services\OpenAiVideoAnalysisService;
use PHPUnit\Framework\TestCase;

final class OpenAiVideoAnalysisServiceTest extends TestCase
{
    public function testGenerateUsesOpenAiPayloadAndReturnsStructuredJson(): void
    {
        $transport = new class implements GeminiTransport {
            public array $lastHeaders = [];
            public string $lastUrl = '';
            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, int $responseLimitBytes): GeminiHttpResponse
            {
                $this->lastHeaders = $headers;
                $this->lastUrl = $url;

                return new GeminiHttpResponse(200, ['Content-Type' => 'application/json'], json_encode([
                    'choices' => [[
                        'message' => ['content' => '{"video_summary":"ok","clips":[]}'],
                    ]],
                ], JSON_THROW_ON_ERROR));
            }

            public function upload(string $url, array $headers, string $absolutePath, int $sizeBytes, int $timeoutSeconds, int $responseLimitBytes): GeminiHttpResponse
            {
                throw new \RuntimeException('not used');
            }
        };

        $storage = new class implements PrivateStorage {
            public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
            {
                throw new \RuntimeException('not used');
            }

            public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
            {
                throw new \RuntimeException('not used');
            }

            public function absolutePath(string $objectKey): string
            {
                return __FILE__;
            }

            public function delete(string $objectKey): void
            {
            }
        };

        $service = new OpenAiVideoAnalysisService(
            $transport,
            $storage,
            'sk-test-key',
            'gpt-4o-mini',
            'https://api.openai.com/v1',
            30,
            1048576
        );
        $file = new GeminiFile('files/openai-clip', 'https://api.openai.com/v1/files/openai-clip', 'video/mp4', 'ACTIVE');

        $result = $service->generate($file, 'Analise este vídeo', [
            'type' => 'object',
            'properties' => [
                'video_summary' => ['type' => 'string'],
                'clips' => ['type' => 'array'],
            ],
            'required' => ['video_summary', 'clips'],
            'additionalProperties' => false,
        ]);

        self::assertSame('{"video_summary":"ok","clips":[]}', $result);
        self::assertSame('https://api.openai.com/v1/chat/completions', $transport->lastUrl);
        self::assertSame('Bearer sk-test-key', $transport->lastHeaders['Authorization'] ?? null);
        $body = json_decode((string) $transport->lastHeaders['Content-Type'] ?? 'null', true);
        self::assertNull($body);
        self::assertSame('application/json', $transport->lastHeaders['Content-Type'] ?? null);
    }
}
