<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\SmtpSocket;
use App\Services\SmtpMailer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    public function testLongEmailDocumentUsesBoundedQuotedPrintableLinesWithoutChangingUtf8OrUrls(): void
    {
        $socket=new FakeSmtpSocket(["220 ready\r\n","250 hello\r\n","250 sender\r\n","250 recipient\r\n","354 data\r\n","250 queued\r\n","221 bye\r\n"]);
        $url='https://example.test/confirmar?token='.str_repeat('abcdef0123456789',90).'&origem=conta';
        $html=(new \App\Communications\EmailDocument())->render('Confirmação de e-mail','<h1>Olá, João &amp; Lívia.</h1><p><a href="'.$url.'">Confirmar endereço</a></p>')."\n.ponto\rÚltima linha\r\n";
        (new SmtpMailer('smtp.example.test',465,'ssl','','','no-reply@example.test','ClipLab',8,$socket))->send('person@example.test','Confirmação de e-mail',$html);
        $message=$socket->writes[4];
        self::assertStringEndsWith("\r\n.\r\n",$message);
        [$headers,$wireBody]=explode("\r\n\r\n",substr($message,0,-5),2);
        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable',$headers);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8',$headers);
        // SMTP receivers remove one transparency dot before MIME decoding.
        $encoded=preg_replace('/^\.\./m','.',$wireBody);
        foreach(explode("\r\n",$encoded) as $line)self::assertLessThanOrEqual(76,strlen($line));
        foreach(explode("\r\n",$wireBody) as $line)self::assertLessThanOrEqual(998,strlen($line));
        self::assertDoesNotMatchRegularExpression('/[\x80-\xff]/',$encoded);
        $normalized=str_replace("\n","\r\n",str_replace(["\r\n","\r"],"\n",$html));
        self::assertSame($normalized,quoted_printable_decode($encoded));
        self::assertStringContainsString("\r\n..ponto\r\n",$wireBody);
    }

    public function testQuitDisconnectDoesNotTurnAcceptedMessageIntoRetry():void
    {
        $socket=new FakeSmtpSocket(["220 ready\r\n","250 hello\r\n","250 sender\r\n","250 recipient\r\n","354 data\r\n","250 queued\r\n"]);
        (new SmtpMailer('smtp.example.test',465,'ssl','','','no-reply@example.test','ClipLab',8,$socket))->send('person@example.test','Assunto','<p>Oi</p>');
        self::assertContains("QUIT\r\n",$socket->writes);
    }
    public function testTlsAuthenticationAndMessageProtocolAreCompletedWithDotStuffing(): void
    {
        $socket = new FakeSmtpSocket(["220 ready\r\n", "250-smtp.example.test\r\n", "250 STARTTLS\r\n", "220 tls\r\n", "250 hello\r\n", "334 user\r\n", "334 pass\r\n", "235 authenticated\r\n", "250 sender\r\n", "250 recipient\r\n", "354 data\r\n", "250 queued\r\n", "221 bye\r\n"]);
        $mailer = new SmtpMailer('smtp.example.test', 587, 'tls', 'mailer@example.test', 'top-secret', 'no-reply@example.test', 'ClipLab', 10, $socket);
        $mailer->send('person@example.test', 'Redefina sua senha', "<p>linha</p>\r\n.ponto");

        self::assertSame(['smtp.example.test', 587, 10, false], $socket->connection);
        self::assertTrue($socket->cryptoEnabled);
        self::assertContains("AUTH LOGIN\r\n", $socket->writes);
        self::assertContains(base64_encode('mailer@example.test') . "\r\n", $socket->writes);
        self::assertContains(base64_encode('top-secret') . "\r\n", $socket->writes);
        self::assertStringContainsString("\r\n..ponto\r\n.\r\n", implode('', $socket->writes));
    }

    public function testSslConnectsImplicitlyWithoutStartTls(): void
    {
        $socket = new FakeSmtpSocket(["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n", "354 data\r\n", "250 queued\r\n", "221 bye\r\n"]);
        (new SmtpMailer('smtp.example.test', 465, 'ssl', '', '', 'no-reply@example.test', 'ClipLab', 8, $socket))->send('person@example.test', 'Assunto', '<p>Oi</p>');

        self::assertSame(['smtp.example.test', 465, 8, true], $socket->connection);
        self::assertNotContains("STARTTLS\r\n", $socket->writes);
    }
}

final class FakeSmtpSocket implements SmtpSocket
{
    /** @var array<int, string> */ public array $writes = [];
    /** @var array{string, int, int, bool}|null */ public ?array $connection = null;
    public bool $cryptoEnabled = false;
    /** @param array<int, string> $responses */ public function __construct(private array $responses) {}
    public function connect(string $host, int $port, int $timeout, bool $ssl): void { $this->connection = [$host, $port, $timeout, $ssl]; }
    public function readLine(): string { return array_shift($this->responses) ?? ''; }
    public function write(string $data): void { $this->writes[] = $data; }
    public function enableCrypto(): void { $this->cryptoEnabled = true; }
    public function close(): void {}
}
