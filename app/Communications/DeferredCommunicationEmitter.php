<?php
declare(strict_types=1);
namespace App\Communications;
use App\Contracts\CommunicationEmitter;
use DateTimeImmutable;

/** Defers credential initialization; never suppresses emission failures. */
final class DeferredCommunicationEmitter implements CommunicationEmitter
{
    private ?CommunicationEmitter $resolved=null;
    private \Closure $factory;
    public function __construct(callable $factory){$this->factory=\Closure::fromCallable($factory);}
    private function delegate():CommunicationEmitter{return $this->resolved??=($this->factory)();}
    public function emit(int $userId,string $event,array $variables,string $dedupeKey,?string $recipient=null,array $channels=['in_app','email'],?DateTimeImmutable $availableAt=null):void
    {$this->delegate()->emit($userId,$event,$variables,$dedupeKey,$recipient,$channels,$availableAt);}
    public function cancelByDedupePrefix(int $userId,string $prefix):void{$this->delegate()->cancelByDedupePrefix($userId,$prefix);}
}
