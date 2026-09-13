<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\EmailTestSendService; use App\Contracts\Mailer; use PHPUnit\Framework\TestCase;
final class EmailTestSendServiceTest extends TestCase { public function testSendsOnlyToAuthenticatedAdminAddress():void{$mailer=new TestMailSink();$s=new EmailTestSendService($mailer);$s->send('admin@example.test','admin@example.test','Teste','<p>Teste</p>');self::assertSame('admin@example.test',$mailer->to);$this->expectException(\InvalidArgumentException::class);$s->send('admin@example.test','other@example.test','Teste','<p>Teste</p>');}}
final class TestMailSink implements Mailer {public string $to='';public function send(string $recipient,string $subject,string $html):void{$this->to=$recipient;}}
