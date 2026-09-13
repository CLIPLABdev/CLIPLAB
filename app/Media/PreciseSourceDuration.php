<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

/** Request/claim-local measurement; never deserialize this from client input. */
final class PreciseSourceDuration
{
    /** @param array<string,int|string> $identity */
    public function __construct(private array $identity, private int $durationMilliseconds)
    {
        if ($durationMilliseconds < 1000 || $durationMilliseconds > 86400000
            || !is_int($identity['project_id'] ?? null) || !is_int($identity['owner_id'] ?? null)
            || !is_int($identity['id'] ?? null) || $identity['project_id'] < 1
            || $identity['owner_id'] < 1 || $identity['id'] < 1) {
            throw new InvalidArgumentException('Precise source measurement is invalid.');
        }
    }

    public function milliseconds(): int { return $this->durationMilliseconds; }
    public function endDecimal(): string
    {
        return intdiv($this->durationMilliseconds,1000).'.'.str_pad((string)($this->durationMilliseconds % 1000),3,'0',STR_PAD_LEFT);
    }
    public function projectId(): int { return $this->identity['project_id']; }
    public function ownerId(): int { return $this->identity['owner_id']; }
    public function sourceId(): int { return $this->identity['id']; }
    /** @param array<string,int|string> $identity */
    public function matches(array $identity): bool { return $this->identity === $identity; }
}
