<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogMailer;
use PHPUnit\Framework\TestCase;

final class LogMailerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/cliplab-password-reset-' . bin2hex(random_bytes(8)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testNeverWritesTheResetLinkOrTokenToTheDevelopmentLog(): void
    {
        $mailer = new LogMailer($this->logFile);

        $mailer->send('person@example.com', 'Redefina sua senha', '<a href="https://example.test/redefinir-senha?token=reset-token">Redefinir senha</a>');

        $contents = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('development-mail', $contents);
        self::assertStringContainsString('recipient=person@example.com', $contents);
        self::assertStringNotContainsString('https://example.test/redefinir-senha', $contents);
        self::assertStringNotContainsString('reset-token', $contents);
        self::assertStringNotContainsString('password=', $contents);
    }
}
