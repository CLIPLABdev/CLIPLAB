<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NativeMailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeMailerTest extends TestCase
{
    public function testRejectsHeaderInjectionBeforeAttemptingToSend(): void
    {
        $mailer = new NativeMailer('no-reply@example.test', 'ClipLab');

        $this->expectException(RuntimeException::class);
        $mailer->send("person@example.test\r\nBcc: attacker@example.test", 'Redefina sua senha', '<a href="https://example.test">Redefinir senha</a>');
    }
}
