<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\DeferredCommunicationEmitter;
use App\Contracts\CommunicationEmitter;
use PHPUnit\Framework\TestCase;
final class DeferredCommunicationEmitterTest extends TestCase
{
    public function testOnlyResolvesWhenUsedAndCachesTheDelegate():void
    {
        $count=0;$inner=$this->createMock(CommunicationEmitter::class);
        $inner->expects(self::once())->method('emit')->with(1,'auth.welcome',['nome_usuario'=>'Ana'],'welcome:1',null,['in_app','email'],null);
        $inner->expects(self::once())->method('cancelByDedupePrefix')->with(1,'reset:1:');
        $emitter=new DeferredCommunicationEmitter(function()use(&$count,$inner):CommunicationEmitter{$count++;return $inner;});
        self::assertSame(0,$count);
        $emitter->emit(1,'auth.welcome',['nome_usuario'=>'Ana'],'welcome:1');
        $emitter->cancelByDedupePrefix(1,'reset:1:');self::assertSame(1,$count);
    }
}
