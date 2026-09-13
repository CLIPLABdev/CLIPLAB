<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Repositories\SystemLogRepository;
use App\Services\AdminGeminiSettingsService;
use App\Services\RateLimiter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AdminTestDatabase;

final class AdminGeminiSettingsServiceTest extends TestCase
{
    private string $key;
    /** @var array<string,mixed> */
    private array $baseline;

    protected function setUp(): void
    {
        $this->key = base64_encode(str_repeat("\x44", 32));
        $this->baseline = [
            'api_key' => 'environment-key',
            'model' => 'gemini-2.5-flash',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'http_timeout_seconds' => 30,
            'response_limit_bytes' => 65536,
            'file_poll_seconds' => 15,
            'validation_attempts' => 2,
            'credits_per_minute' => 1,
        ];
    }

    public function testEncryptedOverrideIsMaskedPubliclyAndBlankSecretPreservesIt(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $service = new AdminGeminiSettingsService($pdo, $this->baseline, $this->key);

        $service->save($ids['admin_id'], ['model' => 'gemini-2.5-pro', 'api_key' => 'override-secret-1234']);
        $public = $service->publicState();
        self::assertTrue($public['has_database_key']);
        self::assertSame('••••1234', $public['api_key_mask']);
        self::assertArrayNotHasKey('api_key', $public);
        self::assertSame('override-secret-1234', $service->effective()['api_key']);
        $stored = (string) $pdo->query('SELECT api_key_ciphertext FROM gemini_settings WHERE id = 1')->fetchColumn();
        self::assertStringNotContainsString('override-secret-1234', $stored);

        $service->save($ids['admin_id'], ['model' => 'gemini-2.5-flash', 'api_key' => '']);
        self::assertSame('override-secret-1234', $service->effective()['api_key']);
        self::assertSame('gemini-2.5-flash', $service->effective()['model']);
    }

    public function testTamperedDatabaseSecretFailsClosedWithoutLeakingCiphertext(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $service = new AdminGeminiSettingsService($pdo, $this->baseline, $this->key);
        $service->save($ids['admin_id'], ['model' => 'gemini-2.5-flash', 'api_key' => 'top-secret']);
        $pdo->exec("UPDATE gemini_settings SET api_key_ciphertext = api_key_ciphertext || 'x' WHERE id = 1");

        try {
            $service->effective();
            self::fail('Tampered encrypted settings were accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Encrypted secret is invalid.', $exception->getMessage());
            self::assertStringNotContainsString('top-secret', $exception->getMessage());
        }
    }

    public function testConnectionCheckUsesSyntheticPostFixedHostAndRateLimitWithoutReturningRawBody(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $transport = new RecordingAdminGeminiTransport('{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"OK"}]}}],"raw":"raw-provider-body"}');
        $service = new AdminGeminiSettingsService(
            $pdo,
            $this->baseline,
            $this->key,
            $transport,
            new RateLimiter($pdo),
            new SystemLogRepository($pdo)
        );

        $result = $service->testConnection($ids['admin_id'], '198.51.100.8');
        self::assertSame(['ok' => true, 'code' => 'connected', 'message' => 'Conexão com o Gemini confirmada.'], $result);
        self::assertSame('POST', $transport->method);
        self::assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models/', $transport->url);
        self::assertStringNotContainsString('environment-key', $transport->url);
        self::assertSame('environment-key', $transport->headers['x-goog-api-key']);
        self::assertStringContainsString('Responda apenas OK', (string) $transport->body);
        $payload = json_decode((string) $transport->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(256, $payload['generationConfig']['maxOutputTokens']);
        self::assertLessThanOrEqual(1024, $payload['generationConfig']['maxOutputTokens']);
        self::assertStringNotContainsString('raw-provider-body', json_encode($result, JSON_THROW_ON_ERROR));

        $service->testConnection($ids['admin_id'], '198.51.100.8');
        $service->testConnection($ids['admin_id'], '198.51.100.8');
        self::assertSame(['ok' => false, 'code' => 'rate_limited', 'message' => 'Aguarde antes de testar novamente.'], $service->testConnection($ids['admin_id'], '198.51.100.8'));
    }

    /** @dataProvider invalidSecretProvider */
    public function testRejectsSecretsThatTheRuntimeCannotUse(string $secret): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $service = new AdminGeminiSettingsService($pdo, $this->baseline, $this->key);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->save($ids['admin_id'], ['model' => 'gemini-2.5-flash', 'api_key' => $secret]);
        } finally {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM gemini_settings')->fetchColumn());
        }
    }

    /** @return iterable<string,array{string}> */
    public function invalidSecretProvider(): iterable
    {
        yield 'too short' => ['short'];
        yield 'too long' => [str_repeat('A', 513)];
        yield 'control' => ["valid-key\nunsafe"];
        yield 'non ascii' => ['válida-mas-unicode'];
        yield 'surrounding space' => [' valid-key-123 '];
    }

    public function testShortEnvironmentSecretIsNeverRevealedByMask(): void
    {
        $pdo = AdminTestDatabase::create();
        AdminTestDatabase::seed($pdo);
        $baseline = $this->baseline;
        $baseline['api_key'] = 'abc';

        $state = (new AdminGeminiSettingsService($pdo, $baseline, $this->key))->publicState();
        self::assertSame('••••', $state['api_key_mask']);
        self::assertStringNotContainsString('abc', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function testPublicStateReportsWhetherDatabaseOverridesCanBeEncrypted(): void
    {
        $pdo = AdminTestDatabase::create();
        AdminTestDatabase::seed($pdo);

        $ready = (new AdminGeminiSettingsService($pdo, $this->baseline, $this->key))->publicState();
        $missing = (new AdminGeminiSettingsService($pdo, $this->baseline, ''))->publicState();
        $malformed = (new AdminGeminiSettingsService($pdo, $this->baseline, base64_encode('too-short')))->publicState();

        self::assertArrayHasKey('encryption_ready', $ready);
        self::assertTrue($ready['encryption_ready']);
        self::assertFalse($missing['encryption_ready']);
        self::assertFalse($malformed['encryption_ready']);
    }

    public function testTwoHundredResponseWithoutStoppedTextCandidateIsNotReportedAsConnected(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $transport = new RecordingAdminGeminiTransport('{"candidates":[{"finishReason":"SAFETY","content":{"parts":[]}}]}');
        $service = new AdminGeminiSettingsService($pdo, $this->baseline, $this->key, $transport, new RateLimiter($pdo), new SystemLogRepository($pdo));

        self::assertSame(
            ['ok' => false, 'code' => 'invalid_provider_response', 'message' => 'O Gemini respondeu, mas o teste não foi confirmado.'],
            $service->testConnection($ids['admin_id'], '198.51.100.9')
        );
    }

    public function testSettingsMutationRevalidatesActiveAdminBeforePersisting(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $service = new AdminGeminiSettingsService($pdo, $this->baseline, $this->key);

        $this->expectException(\DomainException::class);
        try {
            $service->save($ids['user_id'], ['model' => 'gemini-2.5-flash', 'api_key' => 'valid-key-123']);
        } finally {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM gemini_settings')->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM system_logs')->fetchColumn());
        }
    }

    public function testConnectionDoesNotSendAnInvalidEnvironmentSecret(): void
    {
        $pdo = AdminTestDatabase::create();
        $ids = AdminTestDatabase::seed($pdo);
        $baseline = $this->baseline;
        $baseline['api_key'] = "bad\nkey";
        $transport = new RecordingAdminGeminiTransport('{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"OK"}]}}]}');
        $service = new AdminGeminiSettingsService($pdo, $baseline, $this->key, $transport, new RateLimiter($pdo), new SystemLogRepository($pdo));

        self::assertSame(
            ['ok' => false, 'code' => 'configuration_or_network_error', 'message' => 'Não foi possível confirmar a conexão agora.'],
            $service->testConnection($ids['admin_id'], '198.51.100.10')
        );
        self::assertSame('', $transport->method);
    }
}

final class RecordingAdminGeminiTransport implements GeminiTransport
{
    public string $method = '';
    public string $url = '';
    /** @var array<string,string> */
    public array $headers = [];
    public ?string $body = null;

    public function __construct(private string $responseBody = '{}')
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, int $responseLimitBytes): GeminiHttpResponse
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

        return new GeminiHttpResponse(200, ['content-type' => 'application/json'], $this->responseBody);
    }

    public function upload(string $url, array $headers, string $absolutePath, int $sizeBytes, int $timeoutSeconds, int $responseLimitBytes): GeminiHttpResponse
    {
        throw new \LogicException('Admin connectivity check must not upload files.');
    }
}
