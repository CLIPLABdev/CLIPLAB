<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogMailer;
use App\Services\MailerFactory;
use App\Services\NativeMailer;
use App\Services\SmtpMailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailerFactoryTest extends TestCase
{
    public function testSelectsEveryExplicitTransport(): void
    {
        self::assertInstanceOf(LogMailer::class, MailerFactory::make(['transport' => 'log', 'environment' => 'testing', 'log_file' => 'mail.log']));
        self::assertInstanceOf(NativeMailer::class, MailerFactory::make(['transport' => 'mail', 'from_address' => 'no-reply@example.test']));
        self::assertInstanceOf(SmtpMailer::class, MailerFactory::make($this->smtpConfig()));
    }

    public function testInvalidTransportFailsClearlyInsteadOfFallingBack(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported mail transport.');
        MailerFactory::make(['transport' => 'invalid']);
    }

    public function testRejectsProductionLogTransport(): void
    {
        $this->expectException(RuntimeException::class);
        MailerFactory::make(['transport' => 'log', 'environment' => 'production', 'log_file' => 'mail.log']);
    }

    public function testSmtpRequiresHostWithoutExposingPassword(): void
    {
        $config = $this->smtpConfig();
        $config['smtp_host'] = '';
        $config['smtp_password'] = 'must-not-leak';
        try {
            MailerFactory::make($config);
            self::fail('Invalid SMTP configuration was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('must-not-leak', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function smtpConfig(): array
    {
        return ['transport' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'user', 'smtp_password' => 'secret', 'from_address' => 'no-reply@example.test', 'from_name' => 'ClipLab', 'smtp_timeout' => 10];
    }
}
