<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class GeminiConfigTest extends TestCase
{
    /** @var list<string> */
    private const VARIABLES = [
        'GEMINI_API_KEY',
        'GEMINI_MODEL',
        'GEMINI_HTTP_TIMEOUT_SECONDS',
        'GEMINI_RESPONSE_LIMIT_BYTES',
        'GEMINI_FILE_POLL_SECONDS',
        'GEMINI_VALIDATION_ATTEMPTS',
        'GEMINI_CREDITS_PER_MINUTE',
    ];

    protected function setUp(): void
    {
        $this->clearControlledEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearControlledEnvironment();
    }

    public function testGeminiDefaultsAreBoundedAndContainNoSecret(): void
    {
        $config = $this->loadConfig();

        self::assertSame('', $config['api_key']);
        self::assertSame('', $config['model']);
        self::assertSame('https://generativelanguage.googleapis.com', $config['base_url']);
        self::assertSame(180, $config['http_timeout_seconds']);
        self::assertSame(1048576, $config['response_limit_bytes']);
        self::assertSame(15, $config['file_poll_seconds']);
        self::assertSame(2, $config['validation_attempts']);
        self::assertSame(1, $config['credits_per_minute']);
    }

    public function testOperatorValuesAreTrimmedAndClampedToSafeBounds(): void
    {
        self::assertTrue(putenv('GEMINI_API_KEY= test-only-key '));
        self::assertTrue(putenv('GEMINI_MODEL= test-only-model '));
        self::assertTrue(putenv('GEMINI_HTTP_TIMEOUT_SECONDS=9999'));
        self::assertTrue(putenv('GEMINI_RESPONSE_LIMIT_BYTES=1'));
        self::assertTrue(putenv('GEMINI_FILE_POLL_SECONDS=9999'));
        self::assertTrue(putenv('GEMINI_VALIDATION_ATTEMPTS=99'));
        self::assertTrue(putenv('GEMINI_CREDITS_PER_MINUTE=0'));

        $config = $this->loadConfig();

        self::assertSame('test-only-key', $config['api_key']);
        self::assertSame('test-only-model', $config['model']);
        self::assertSame(300, $config['http_timeout_seconds']);
        self::assertSame(16384, $config['response_limit_bytes']);
        self::assertSame(300, $config['file_poll_seconds']);
        self::assertSame(2, $config['validation_attempts']);
        self::assertSame(1, $config['credits_per_minute']);
    }

    public function testNumericLowerAndUpperBoundsCannotBeBypassed(): void
    {
        self::assertTrue(putenv('GEMINI_HTTP_TIMEOUT_SECONDS=-1'));
        self::assertTrue(putenv('GEMINI_RESPONSE_LIMIT_BYTES=999999999'));
        self::assertTrue(putenv('GEMINI_FILE_POLL_SECONDS=-1'));
        self::assertTrue(putenv('GEMINI_CREDITS_PER_MINUTE=999999'));

        $config = $this->loadConfig();

        self::assertSame(10, $config['http_timeout_seconds']);
        self::assertSame(4194304, $config['response_limit_bytes']);
        self::assertSame(5, $config['file_poll_seconds']);
        self::assertSame(100, $config['credits_per_minute']);
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/gemini.php';

        return $config;
    }

    private function clearControlledEnvironment(): void
    {
        foreach (self::VARIABLES as $name) {
            self::assertTrue(putenv($name), "Unable to scrub controlled {$name} variable.");
        }
    }
}
