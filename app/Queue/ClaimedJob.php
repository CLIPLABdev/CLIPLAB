<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;

final class ClaimedJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private int $id,
        private string $queueName,
        private string $type,
        private int $projectId,
        private array $payload,
        private string $workerId,
        private string $leaseToken,
        private int $attempts,
        private int $maxAttempts
    ) {
        if ($id <= 0 || $projectId <= 0 || $attempts <= 0 || $maxAttempts <= 0) {
            throw new InvalidArgumentException('Claimed job identifiers and attempts must be positive.');
        }
        if (trim($queueName) === '' || trim($type) === '' || trim($workerId) === '' || $leaseToken === '') {
            throw new InvalidArgumentException('Claimed job fields must not be empty.');
        }
    }

    public function id(): int { return $this->id; }
    public function queueName(): string { return $this->queueName; }
    public function type(): string { return $this->type; }
    public function projectId(): int { return $this->projectId; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }
    public function workerId(): string { return $this->workerId; }
    public function leaseToken(): string { return $this->leaseToken; }
    public function attempts(): int { return $this->attempts; }
    public function maxAttempts(): int { return $this->maxAttempts; }
}
