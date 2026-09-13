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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GeminiSecretBoundaryTest extends TestCase
{
    private const SECRET = 'opaque-secret-never-expose';
    private const SESSION = 'https://generativelanguage.googleapis.com/upload/session?token=private';
    private const PROMPT = 'private prompt body';
    private const PATH = 'C:\\private\\customer\\video.mp4';
    private const RESPONSE = '{"provider":"private response"}';

    public function testUnexpectedTransportFailureCannotExposeRequestSecrets(): void
    {
        $transport = new SecretBoundaryTransport(new \RuntimeException(
            self::SECRET . ' ' . self::SESSION . ' ' . self::PROMPT . ' ' . self::PATH . ' ' . self::RESPONSE . ' curl diagnostic'
        ));
        $service = $this->service($transport, new SecretBoundaryStorage(self::PATH));

        try {
            $service->generate(
                new GeminiFile(
                    'files/video-123',
                    'https://generativelanguage.googleapis.com/v1beta/files/video-123',
                    'video/mp4',
                    'ACTIVE'
                ),
                self::PROMPT,
                ['type' => 'object']
            );
            self::fail('Unsafe transport exception escaped.');
        } catch (GeminiException $exception) {
            self::assertSame('ai_unavailable', $exception->publicCode());
            $this->assertContainsNoSensitiveValue($exception->getMessage());
            self::assertNull($exception->getPrevious(), 'Raw provider exceptions must not be chained.');
        }
        self::assertSame(1, $transport->requestCalls);
    }

    public function testStorageFailureCannotExposePrivatePathOrObjectKey(): void
    {
        $transport = new SecretBoundaryTransport(new GeminiHttpResponse(200, [], '{}'));
        $storage = new SecretBoundaryStorage(self::PATH, new \RuntimeException(self::PATH . ' users/7/private.mp4'));
        $service = $this->service($transport, $storage);

        try {
            $service->upload(new ProjectSource(1, 7, 'local', 'users/7/private.mp4', 'video/mp4'));
            self::fail('Unsafe storage exception escaped.');
        } catch (GeminiException $exception) {
            self::assertSame('ai_unavailable', $exception->publicCode());
            $this->assertContainsNoSensitiveValue($exception->getMessage());
            self::assertStringNotContainsString('users/7/private.mp4', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame(0, $transport->requestCalls);
    }

    public function testMalformedProviderResponseIsNeverCopiedIntoException(): void
    {
        $transport = new SecretBoundaryTransport(new GeminiHttpResponse(200, [], self::RESPONSE . self::SECRET));
        $service = $this->service($transport, new SecretBoundaryStorage(self::PATH));

        try {
            $service->getFile('files/video-123');
            self::fail('Malformed response was accepted.');
        } catch (GeminiException $exception) {
            self::assertSame('ai_provider_rejected', $exception->publicCode());
            $this->assertContainsNoSensitiveValue($exception->getMessage());
        }
    }

    public function testPublicExceptionsUseOnlyFixedAllowlistedMessages(): void
    {
        $messages = [];
        foreach (['ai_unconfigured', 'ai_timeout', 'ai_rate_limited', 'ai_unavailable', 'ai_provider_rejected'] as $code) {
            $exception = GeminiException::withCode($code);
            self::assertSame($code, $exception->publicCode());
            $this->assertContainsNoSensitiveValue($exception->getMessage());
            self::assertNotSame('', $exception->getMessage());
            $messages[$code] = $exception->getMessage();
        }
        self::assertCount(5, array_unique($messages));
    }

    public function testUnknownPublicErrorCodeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GeminiException::withCode(self::SECRET);
    }

    public function testMissingConfigurationDoesNotBreakWorkerCompositionAndFailsBeforeNetwork(): void
    {
        $transport = new SecretBoundaryTransport(new \RuntimeException('Network must not be called.'));
        $service = new GeminiService(
            $transport,
            new SecretBoundaryStorage(self::PATH),
            '',
            '',
            'https://generativelanguage.googleapis.com',
            10,
            4096
        );

        try {
            $service->getFile('files/video-123');
            self::fail('Missing configuration was accepted.');
        } catch (GeminiException $exception) {
            self::assertSame('ai_unconfigured', $exception->publicCode());
            self::assertFalse($exception->isTransient());
        }
        self::assertSame(0, $transport->requestCalls);
    }

    private function service(GeminiTransport $transport, PrivateStorage $storage): GeminiService
    {
        return new GeminiService(
            $transport,
            $storage,
            self::SECRET,
            'gemini-model-test',
            'https://generativelanguage.googleapis.com',
            10,
            4096
        );
    }

    private function assertContainsNoSensitiveValue(string $message): void
    {
        foreach ([self::SECRET, self::SESSION, self::PROMPT, self::PATH, self::RESPONSE, 'curl diagnostic'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $message);
        }
    }
}

final class SecretBoundaryTransport implements GeminiTransport
{
    /** @var GeminiHttpResponse|\Throwable */
    private $outcome;
    public int $requestCalls = 0;
    public int $uploadCalls = 0;

    /** @param GeminiHttpResponse|\Throwable $outcome */
    public function __construct($outcome)
    {
        $this->outcome = $outcome;
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        $this->requestCalls++;

        return $this->resolve();
    }

    public function upload(
        string $url,
        array $headers,
        string $absolutePath,
        int $sizeBytes,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        $this->uploadCalls++;

        return $this->resolve();
    }

    private function resolve(): GeminiHttpResponse
    {
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class SecretBoundaryStorage implements PrivateStorage
{
    public function __construct(private string $path, private ?\Throwable $failure = null)
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
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return $this->path;
    }

    public function delete(string $objectKey): void
    {
        throw new \LogicException('Not used.');
    }
}
