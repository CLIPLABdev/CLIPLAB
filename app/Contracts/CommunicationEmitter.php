<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

interface CommunicationEmitter
{
    /**
     * Records a communication event durably. Implementations must not deliver synchronously
     * or commit a transaction owned by their caller.
     *
     * @param array<string,mixed> $variables
     * @param list<string> $channels
     */
    public function emit(
        int $userId,
        string $event,
        array $variables,
        string $dedupeKey,
        ?string $recipient = null,
        array $channels = ['in_app', 'email'],
        ?DateTimeImmutable $availableAt = null
    ): void;

    /** Cancels pending deliveries for one user with an exact, caller-controlled key prefix. */
    public function cancelByDedupePrefix(int $userId, string $prefix): void;
}
