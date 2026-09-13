<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Gemini\GeminiException;
use App\Gemini\GeminiFile;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Services\GeminiService;
use PHPUnit\Framework\TestCase;

final class GeminiServiceTest extends TestCase
{
    public function testUnavailableResponseKeepsSafeStatusAndRetryHintWithoutProviderBody(): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse(503, ['Retry-After'=>'120'], '{"error":"secret-test-key private provider body"}'));
        try {
            $this->service->generate($this->activeFile(), 'prompt-v1', ['type'=>'object']);
            self::fail('Expected transient provider failure.');
        } catch (GeminiException $exception) {
            self::assertTrue(method_exists($exception, 'safeDiagnostic'), 'Provider HTTP status is discarded today.');
            self::assertSame(['failure_kind'=>'http','http_status'=>503,'retry_after_seconds'=>120], $exception->safeDiagnostic());
            self::assertSame(120, $exception->retryAfterSeconds());
            self::assertStringNotContainsString('secret-test-key', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private string $directory;
    private string $privatePath;
    private ProjectSource $source;
    private RecordingGeminiTransport $transport;
    private GeminiService $service;
    /** @var float */
    private $now = 100.0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gemini-service-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->privatePath = $this->directory . '/private-video.mp4';
        file_put_contents($this->privatePath, str_repeat('video-bytes-', 7));
        $this->source = new ProjectSource(17, 23, 'local', 'users/23/private-video.mp4', 'video/mp4');
        $this->transport = new RecordingGeminiTransport();
        $storage = new GeminiServiceStorage($this->privatePath);
        $this->service = new GeminiService(
            $this->transport,
            $storage,
            'secret-test-key',
            'gemini-model-test',
            'https://generativelanguage.googleapis.com',
            10,
            4096,
            function (): float {
                return $this->now;
            }
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->privatePath);
        @rmdir($this->directory);
    }

    public function testUploadUsesHeaderKeyAndPrivateStreamWithoutQuerySecret(): void
    {
        $this->queueUpload('ACTIVE');

        $file = $this->service->upload($this->source);

        self::assertSame('files/video-123', $file->name());
        self::assertCount(1, $this->transport->requests);
        self::assertCount(1, $this->transport->uploads);
        $start = $this->transport->requests[0];
        $finalize = $this->transport->uploads[0];
        self::assertSame('POST', $start['method']);
        self::assertSame('https://generativelanguage.googleapis.com/upload/v1beta/files', $start['url']);
        self::assertStringNotContainsString('secret-test-key', $start['url']);
        self::assertSame('secret-test-key', $this->header($start['headers'], 'x-goog-api-key'));
        self::assertSame('resumable', $this->header($start['headers'], 'x-goog-upload-protocol'));
        self::assertSame('start', $this->header($start['headers'], 'x-goog-upload-command'));
        self::assertSame((string) filesize($this->privatePath), $this->header($start['headers'], 'x-goog-upload-header-content-length'));
        self::assertSame('video/mp4', $this->header($start['headers'], 'x-goog-upload-header-content-type'));
        self::assertSame(['file' => ['displayName' => 'project-23']], json_decode((string) $start['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($this->privatePath, $finalize['path']);
        self::assertSame(filesize($this->privatePath), $finalize['size']);
        self::assertSame('0', $this->header($finalize['headers'], 'x-goog-upload-offset'));
        self::assertSame('upload, finalize', $this->header($finalize['headers'], 'x-goog-upload-command'));
        self::assertNull($this->header($finalize['headers'], 'x-goog-api-key'));
    }

    public function testUploadUsesOneMonotonicDeadlineAcrossStartAndFinalize(): void
    {
        $this->transport->queueRequest(
            new GeminiHttpResponse(200, ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session?opaque=1'], ''),
            function (): void {
                $this->now = 106.2;
            }
        );
        $this->transport->queueUpload($this->uploadResponse('PROCESSING'));

        $this->service->upload($this->source);

        self::assertSame(10, $this->transport->requests[0]['timeout']);
        self::assertSame(4, $this->transport->uploads[0]['timeout']);
    }

    public function testUploadDoesNotStartFinalizeAfterDeadlineExpires(): void
    {
        $this->transport->queueRequest(
            new GeminiHttpResponse(200, ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session'], ''),
            function (): void {
                $this->now = 110.0;
            }
        );

        try {
            $this->service->upload($this->source);
            self::fail('Finalize started after the shared deadline expired.');
        } catch (GeminiException $exception) {
            self::assertSame('ai_timeout', $exception->publicCode());
            self::assertTrue($exception->isTransient());
        }
        self::assertSame([], $this->transport->uploads);
    }

    /** @dataProvider providerStates */
    public function testUploadParsesWrappedFileStates(?string $providerState, string $expectedState): void
    {
        $this->queueUpload($providerState);

        $file = $this->service->upload($this->source);

        self::assertSame($expectedState, $file->state());
    }

    /** @return iterable<string, array{?string, string}> */
    public function providerStates(): iterable
    {
        yield 'processing' => ['PROCESSING', 'PROCESSING'];
        yield 'active' => ['ACTIVE', 'ACTIVE'];
        yield 'failed' => ['FAILED', 'FAILED'];
        yield 'missing state' => [null, 'PROCESSING'];
        yield 'unspecified state' => ['STATE_UNSPECIFIED', 'PROCESSING'];
    }

    public function testGetFileUsesDirectObjectAndFixedResourcePath(): void
    {
        $this->transport->queueRequest($this->directFileResponse('ACTIVE'));

        $file = $this->service->getFile('files/video-123');

        self::assertSame('ACTIVE', $file->state());
        self::assertSame('GET', $this->transport->requests[0]['method']);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/files/video-123', $this->transport->requests[0]['url']);
        self::assertSame('secret-test-key', $this->header($this->transport->requests[0]['headers'], 'x-goog-api-key'));
        self::assertNull($this->transport->requests[0]['body']);
    }

    public function testDeleteTreatsNotFoundAsIdempotentSuccess(): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse(404, ['Content-Type' => 'application/json'], '{"error":"gone"}'));

        $this->service->deleteFile('files/video-123');

        self::assertCount(1, $this->transport->requests);
        self::assertSame('DELETE', $this->transport->requests[0]['method']);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/files/video-123', $this->transport->requests[0]['url']);
    }

    public function testGenerateSendsFileReferenceAndStructuredSchema(): void
    {
        $this->transport->queueRequest($this->generationResponse('{"video_summary":"ok","clips":[]}'));
        $active = $this->activeFile();
        $schema = [
            'type' => 'object',
            'properties' => [
                'nested' => ['type' => 'object', 'additionalProperties' => false],
            ],
            'additionalProperties' => false,
        ];

        $raw = $this->service->generate($active, 'prompt-v1', $schema);

        $request = $this->transport->requests[0];
        $body = json_decode((string) $request['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('POST', $request['method']);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-model-test:generateContent', $request['url']);
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertSame([
            'type' => 'OBJECT',
            'properties' => ['nested' => ['type' => 'OBJECT']],
        ], $body['generationConfig']['responseSchema']);
        self::assertArrayNotHasKey('responseFormat', $body['generationConfig']);
        self::assertFalse($schema['additionalProperties'], 'Preparing the provider payload must not mutate the domain schema.');
        self::assertSame('object', $schema['type'], 'Preparing the provider payload must preserve the domain type names.');
        self::assertSame($active->mimeType(), $body['contents'][0]['parts'][0]['fileData']['mimeType']);
        self::assertSame($active->uri(), $body['contents'][0]['parts'][0]['fileData']['fileUri']);
        self::assertSame('prompt-v1', $body['contents'][0]['parts'][1]['text']);
        self::assertSame('{"video_summary":"ok","clips":[]}', $raw);
    }

    public function testGenerateJoinsFinalTextPartsButIgnoresThoughtAndSignatureOnlyParts(): void
    {
        $body = json_encode([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [
                    ['thought' => true, 'text' => 'private chain of thought'],
                    ['thoughtSignature' => 'opaque-signature'],
                    ['text' => '{"video_'],
                    ['text' => 'summary":"ok","clips":[]}'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->transport->queueRequest(new GeminiHttpResponse(200, [], $body));

        $raw = $this->service->generate($this->activeFile(), 'prompt-v1', ['type' => 'object']);

        self::assertSame('{"video_summary":"ok","clips":[]}', $raw);
    }

    /** @dataProvider ignoredNonFinalCandidates */
    public function testGenerateIgnoresWellFormedNonFinalCandidatesWhenOneStopIsUsable(
        string $finishReason,
        bool $nonFinalFirst
    ): void {
        $final = [
            'finishReason' => 'STOP',
            'content' => ['parts' => [['text' => '{"video_summary":"ok","clips":[]}']]],
        ];
        $nonFinal = [
            'finishReason' => $finishReason,
            'content' => ['parts' => [['text' => 'incomplete provider output']]],
        ];
        $candidates = $nonFinalFirst ? [$nonFinal, $final] : [$final, $nonFinal];
        $this->transport->queueRequest(new GeminiHttpResponse(
            200,
            [],
            json_encode(['candidates' => $candidates], JSON_THROW_ON_ERROR)
        ));

        try {
            $raw = $this->service->generate($this->activeFile(), 'prompt-v1', ['type' => 'object']);
        } catch (GeminiException $exception) {
            self::fail('A well-formed non-final candidate invalidated the single usable STOP candidate.');
        }

        self::assertSame('{"video_summary":"ok","clips":[]}', $raw);
    }

    /** @return iterable<string, array{string, bool}> */
    public function ignoredNonFinalCandidates(): iterable
    {
        yield 'SAFETY before STOP' => ['SAFETY', true];
        yield 'MAX_TOKENS after STOP' => ['MAX_TOKENS', false];
    }

    /** @dataProvider unusableGenerationResponses */
    public function testGenerateRejectsResponsesWithoutExactlyOneUsableFinalCandidate(array $payload): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));

        $this->expectGeminiCode('ai_provider_rejected', false, function (): void {
            $this->service->generate($this->activeFile(), 'prompt-v1', ['type' => 'object']);
        });
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function unusableGenerationResponses(): iterable
    {
        yield 'zero candidates' => [['candidates' => []]];
        yield 'two usable candidates' => [['candidates' => [
            ['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{}']]]],
            ['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{}']]]],
        ]]];
        yield 'thought only' => [['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [['thought' => true, 'text' => 'reasoning'], ['thoughtSignature' => 'sig']]],
        ]]]];
        yield 'safety finish' => [['candidates' => [[
            'finishReason' => 'SAFETY',
            'content' => ['parts' => [['text' => '{}']]],
        ]]]];
        yield 'max tokens finish' => [['candidates' => [[
            'finishReason' => 'MAX_TOKENS',
            'content' => ['parts' => [['text' => '{}']]],
        ]]]];
        yield 'prompt blocked' => [['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []]];
    }

    public function testGenerateRejectsAFileThatIsNotActiveWithoutCallingTransport(): void
    {
        $file = new GeminiFile(
            'files/video-123',
            'https://generativelanguage.googleapis.com/v1beta/files/video-123',
            'video/mp4',
            'PROCESSING'
        );

        $this->expectGeminiCode('ai_provider_rejected', false, function () use ($file): void {
            $this->service->generate($file, 'prompt-v1', ['type' => 'object']);
        });
        self::assertSame([], $this->transport->requests);
    }

    /** @dataProvider unsafeUploadUrls */
    public function testUploadRejectsUnsafeSessionUrl(string $url): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse(200, ['X-Goog-Upload-URL' => $url], ''));

        $this->expectGeminiCode('ai_provider_rejected', false, function (): void {
            $this->service->upload($this->source);
        });
        self::assertSame([], $this->transport->uploads);
    }

    /** @return iterable<string, array{string}> */
    public function unsafeUploadUrls(): iterable
    {
        yield 'plain http' => ['http://generativelanguage.googleapis.com/upload/session'];
        yield 'foreign host' => ['https://evil.example/upload/session'];
        yield 'lookalike host' => ['https://generativelanguage.googleapis.com.evil.example/upload/session'];
        yield 'nonstandard port' => ['https://generativelanguage.googleapis.com:444/upload/session'];
        yield 'userinfo' => ['https://user@generativelanguage.googleapis.com/upload/session'];
        yield 'fragment' => ['https://generativelanguage.googleapis.com/upload/session#secret'];
        yield 'carriage return' => ["https://generativelanguage.googleapis.com/upload/session\rignored"];
        yield 'line feed' => ["https://generativelanguage.googleapis.com/upload/session\nignored"];
        yield 'trailing host dot' => ['https://generativelanguage.googleapis.com./upload/session'];
    }

    public function testUploadRejectsMissingOrDuplicatedSessionHeader(): void
    {
        foreach ([[], ['X-Goog-Upload-URL' => [
            'https://generativelanguage.googleapis.com/upload/one',
            'https://generativelanguage.googleapis.com/upload/two',
        ]]] as $headers) {
            $transport = new RecordingGeminiTransport();
            $transport->queueRequest(new GeminiHttpResponse(200, $headers, ''));
            $service = new GeminiService(
                $transport,
                new GeminiServiceStorage($this->privatePath),
                'secret-test-key',
                'model',
                'https://generativelanguage.googleapis.com',
                10,
                4096
            );

            try {
                $service->upload($this->source);
                self::fail('Missing or duplicate session header was accepted.');
            } catch (GeminiException $exception) {
                self::assertSame('ai_provider_rejected', $exception->publicCode());
            }
            self::assertSame([], $transport->uploads);
        }
    }

    /** @dataProvider malformedFileBodies */
    public function testGetFileRejectsMalformedProviderJson(string $body): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse(200, [], $body));

        $this->expectGeminiCode('ai_provider_rejected', false, function (): void {
            $this->service->getFile('files/video-123');
        });
    }

    /** @return iterable<string, array{string}> */
    public function malformedFileBodies(): iterable
    {
        yield 'not json' => ['not-json'];
        yield 'list' => ['[]'];
        yield 'missing name' => ['{"uri":"https://generativelanguage.googleapis.com/v1beta/files/video-123","mimeType":"video/mp4","state":"ACTIVE"}'];
        yield 'wrong field type' => ['{"name":7,"uri":"https://generativelanguage.googleapis.com/v1beta/files/video-123","mimeType":"video/mp4","state":"ACTIVE"}'];
        yield 'unknown state' => ['{"name":"files/video-123","uri":"https://generativelanguage.googleapis.com/v1beta/files/video-123","mimeType":"video/mp4","state":"UNKNOWN"}'];
    }

    /** @dataProvider httpFailures */
    public function testMapsHttpFailuresWithoutRetryingInternally(int $status, string $code, bool $transient): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse($status, [], '{"provider":"detail"}'));

        $this->expectGeminiCode($code, $transient, function (): void {
            $this->service->getFile('files/video-123');
        });
        self::assertCount(1, $this->transport->requests);
    }

    /** @return iterable<string, array{int, string, bool}> */
    public function httpFailures(): iterable
    {
        yield 'request timeout' => [408, 'ai_timeout', true];
        yield 'rate limited' => [429, 'ai_rate_limited', true];
        yield 'bad request' => [400, 'ai_provider_rejected', false];
        yield 'unauthorized' => [401, 'ai_provider_rejected', false];
        yield 'not found' => [404, 'ai_provider_rejected', false];
        yield 'server error' => [500, 'ai_unavailable', true];
        yield 'gateway error' => [503, 'ai_unavailable', true];
    }

    /** @dataProvider invalidResourceNames */
    public function testFixedFileOperationsRejectNoncanonicalResourceNames(string $resourceName): void
    {
        $this->expectGeminiCode('ai_provider_rejected', false, function () use ($resourceName): void {
            $this->service->getFile($resourceName);
        });
        self::assertSame([], $this->transport->requests);
    }

    /** @return iterable<string, array{string}> */
    public function invalidResourceNames(): iterable
    {
        yield 'path traversal' => ['files/../secret'];
        yield 'query' => ['files/video-123?key=secret'];
        yield 'uppercase' => ['files/Video'];
        yield 'too long' => ['files/' . str_repeat('a', 41)];
    }

    public function testOpaquePrintableApiKeyDoesNotRequireACommercialPrefix(): void
    {
        $transport = new RecordingGeminiTransport();
        $transport->queueRequest($this->directFileResponse('PROCESSING'));
        $service = new GeminiService(
            $transport,
            new GeminiServiceStorage($this->privatePath),
            'opaque-key.with_symbols+value',
            'model',
            'https://generativelanguage.googleapis.com',
            10,
            4096
        );

        $service->getFile('files/video-123');

        self::assertSame('opaque-key.with_symbols+value', $this->header($transport->requests[0]['headers'], 'x-goog-api-key'));
    }

    /** @dataProvider missingConfiguration */
    public function testMissingOrControlCharacterConfigurationFailsOnlyWhenGeminiIsUsed(string $key, string $model): void
    {
        $transport = new RecordingGeminiTransport();
        $service = new GeminiService(
            $transport,
            new GeminiServiceStorage($this->privatePath),
            $key,
            $model,
            'https://generativelanguage.googleapis.com',
            10,
            4096
        );

        $this->expectGeminiCode('ai_unconfigured', false, function () use ($service): void {
            $service->getFile('files/video-123');
        });
        self::assertSame([], $transport->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public function missingConfiguration(): iterable
    {
        yield 'missing key' => ['', 'model'];
        yield 'blank key' => ['   ', 'model'];
        yield 'key with newline' => ["key\nheader", 'model'];
        yield 'missing model' => ['key', ''];
        yield 'model with control' => ['key', "model\rnext"];
    }

    private function queueUpload(?string $state): void
    {
        $this->transport->queueRequest(new GeminiHttpResponse(
            200,
            ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/session?opaque=1'],
            ''
        ));
        $this->transport->queueUpload($this->uploadResponse($state));
    }

    private function uploadResponse(?string $state): GeminiHttpResponse
    {
        $file = [
            'name' => 'files/video-123',
            'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/video-123',
            'mimeType' => 'video/mp4',
        ];
        if ($state !== null) {
            $file['state'] = $state;
        }

        return new GeminiHttpResponse(200, ['Content-Type' => 'application/json'], json_encode(['file' => $file], JSON_THROW_ON_ERROR));
    }

    private function directFileResponse(?string $state): GeminiHttpResponse
    {
        $file = [
            'name' => 'files/video-123',
            'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/video-123',
            'mimeType' => 'video/mp4',
        ];
        if ($state !== null) {
            $file['state'] = $state;
        }

        return new GeminiHttpResponse(200, [], json_encode($file, JSON_THROW_ON_ERROR));
    }

    private function generationResponse(string $text): GeminiHttpResponse
    {
        return new GeminiHttpResponse(200, [], json_encode([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $text]]],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function activeFile(): GeminiFile
    {
        return new GeminiFile(
            'files/video-123',
            'https://generativelanguage.googleapis.com/v1beta/files/video-123',
            'video/mp4',
            'ACTIVE'
        );
    }

    /** @param array<string, string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    private function expectGeminiCode(string $code, bool $transient, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected GeminiException was not thrown.');
        } catch (GeminiException $exception) {
            self::assertSame($code, $exception->publicCode());
            self::assertSame($transient, $exception->isTransient());
        }
    }
}

final class RecordingGeminiTransport implements GeminiTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int, limit: int}> */
    public array $requests = [];
    /** @var list<array{url: string, headers: array<string, string>, path: string, size: int, timeout: int, limit: int}> */
    public array $uploads = [];
    /** @var list<array{response: GeminiHttpResponse|\Throwable, after: ?callable}> */
    private array $requestQueue = [];
    /** @var list<GeminiHttpResponse|\Throwable> */
    private array $uploadQueue = [];

    public function queueRequest($response, ?callable $after = null): void
    {
        $this->requestQueue[] = ['response' => $response, 'after' => $after];
    }

    public function queueUpload($response): void
    {
        $this->uploadQueue[] = $response;
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
            'limit' => $responseLimitBytes,
        ];
        $queued = array_shift($this->requestQueue);
        if ($queued === null) {
            throw new \RuntimeException('No fake response queued.');
        }
        if ($queued['after'] !== null) {
            ($queued['after'])();
        }
        if ($queued['response'] instanceof \Throwable) {
            throw $queued['response'];
        }

        return $queued['response'];
    }

    public function upload(
        string $url,
        array $headers,
        string $absolutePath,
        int $sizeBytes,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        $this->uploads[] = [
            'url' => $url,
            'headers' => $headers,
            'path' => $absolutePath,
            'size' => $sizeBytes,
            'timeout' => $timeoutSeconds,
            'limit' => $responseLimitBytes,
        ];
        $queued = array_shift($this->uploadQueue);
        if ($queued === null) {
            throw new \RuntimeException('No fake upload response queued.');
        }
        if ($queued instanceof \Throwable) {
            throw $queued;
        }

        return $queued;
    }
}

final class GeminiServiceStorage implements PrivateStorage
{
    public function __construct(private string $path)
    {
    }

    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
    {
        throw new \LogicException('Not used.');
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        throw new \LogicException('Not used.');
    }

    public function absolutePath(string $objectKey): string
    {
        return $this->path;
    }

    public function delete(string $objectKey): void
    {
        throw new \LogicException('Not used.');
    }
}
