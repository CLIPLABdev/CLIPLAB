<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Core\Request;
use App\Controllers\BillingWebhookController;
use PHPUnit\Framework\TestCase;

final class RequestBodyLimitTest extends TestCase
{
    public function testCaptureKeepsSignatureBytesAndOnlyReadsBoundedSentinel(): void
    {
        $stream=fopen('php://temp','w+b');
        fwrite($stream,str_repeat('x',500000));rewind($stream);
        $request=Request::capture($stream);
        self::assertSame(262145,strlen($request->rawBody()));
        self::assertSame(262145,ftell($stream));
        $called=false;
        $controller=new BillingWebhookController(function()use(&$called):array{$called=true;return ['status'=>'processed'];},static fn()=>true);
        self::assertSame(413,$controller->stripe($request,['environment'=>'sandbox'])->status());
        self::assertFalse($called);
        fclose($stream);
    }

    public function testCapturePreservesExactSmallBodyAndParsedFormFields(): void
    {
        $stream=fopen('php://temp','w+b');$body="{ \"x\":\"á\" }\n";
        fwrite($stream,$body);rewind($stream);$original=$_POST;
        try {$_POST=['title'=>'Vídeo real'];$request=Request::capture($stream);}
        finally {$_POST=$original;fclose($stream);}
        self::assertSame($body,$request->rawBody());
        self::assertSame('Vídeo real',$request->input('title'));
    }
}
