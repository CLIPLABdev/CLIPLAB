<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $directory;
    private string $logFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/clipforge-logger-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->directory));
        $this->logFile = $this->directory . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testRedactsSensitiveContextRecursivelyAndAddsCorrelationId(): void
    {
        $logger = new Logger($this->logFile);
        $logger->error('request failed', [
            'password' => 'secret',
            'token' => 'abc',
            'MAIL_SMTP_PASSWORD' => 'smtp-secret',
            'user_id' => 9,
            'request' => [
                'Authorization' => 'Bearer hidden',
                'gemini_api_key' => 'another-secret',
            ],
        ]);

        $contents = (string) file_get_contents($this->logFile);
        $entry = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('secret', $contents);
        // A random hexadecimal correlation ID may legitimately contain "abc".
        self::assertSame('[REDACTED]', $entry['context']['token']);
        self::assertStringNotContainsString('Bearer hidden', $contents);
        self::assertSame(9, $entry['context']['user_id']);
        self::assertSame('[REDACTED]', $entry['context']['request']['Authorization']);
        self::assertIsString($entry['correlation_id']);
        self::assertNotSame('', $entry['correlation_id']);
    }

    public function testOperationalSinkNeverReceivesRawErrorOrRequestContext(): void
    {
        $received=[];
        $logger=new Logger($this->logFile,static function(array $event) use (&$received): void { $received[]=$event; });
        $logger->error('private provider response',['request_body'=>'private token','correlation_id'=>str_repeat('a',32)]);
        self::assertCount(1,$received);
        self::assertSame(['level'=>'error','event'=>'system.operation_failed','context'=>['source'=>'application','correlation_id'=>str_repeat('a',32)]],$received[0]);
    }

    public function testOperationalSinkFailurePreservesFileLogging(): void
    {
        $logger=new Logger($this->logFile,static function(): void { throw new \RuntimeException('db unavailable'); });
        $logger->error('Safe error');
        self::assertStringContainsString('Safe error',(string)file_get_contents($this->logFile));
    }
}
