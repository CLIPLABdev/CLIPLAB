<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\NativeSmtpSocket;
use PHPUnit\Framework\TestCase;
final class NativeSmtpDeadlineTest extends TestCase {
    public function testExpiredOperationBudgetStopsReadingEvenWhenStreamHasData():void {
        $socket=new NativeSmtpSocket();$stream=fopen('php://temp','w+');fwrite($stream,"250 buffered\r\n");rewind($stream);
        $reflection=new \ReflectionClass($socket);$property=$reflection->getProperty('stream');$property->setAccessible(true);$property->setValue($socket,$stream);
        $deadline=$reflection->getProperty('deadline');$deadline->setAccessible(true);$deadline->setValue($socket,microtime(true)-1);
        try{$this->expectException(\RuntimeException::class);$socket->readLine();}finally{$socket->close();}
    }
}
