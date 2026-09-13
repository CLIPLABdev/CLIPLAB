<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\CommunicationEmitter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CommunicationEmitterContractTest extends TestCase
{
    public function testProfileCallersCanEmitAndCancelDurableCommunicationByPrefix(): void
    {
        $emitter = new ContractRecordingEmitter();
        $availableAt = new DateTimeImmutable('2026-09-07 12:00:00 UTC');

        $emitter->emit(17, 'account.email_change_requested', ['nome_usuario' => 'Ana'], 'profile-email:17:42', 'ana@example.test', ['email'], $availableAt);
        $emitter->cancelByDedupePrefix(17, 'profile-email:17:');

        self::assertSame([17, 'account.email_change_requested', ['nome_usuario' => 'Ana'], 'profile-email:17:42', 'ana@example.test', ['email'], $availableAt], $emitter->emissions[0]);
        self::assertSame([[17, 'profile-email:17:']], $emitter->cancellations);
    }
}

final class ContractRecordingEmitter implements CommunicationEmitter
{
    /** @var list<array{int,string,array<string,mixed>,string,?string,array<int,string>,?DateTimeImmutable}> */
    public array $emissions = [];

    /** @var list<array{int,string}> */
    public array $cancellations = [];

    public function emit(int $userId, string $event, array $variables, string $dedupeKey, ?string $recipient = null, array $channels = ['in_app', 'email'], ?DateTimeImmutable $availableAt = null): void
    {
        $this->emissions[] = [$userId, $event, $variables, $dedupeKey, $recipient, $channels, $availableAt];
    }

    public function cancelByDedupePrefix(int $userId, string $prefix): void
    {
        $this->cancellations[] = [$userId, $prefix];
    }
}
