<?php

declare(strict_types=1);

namespace App\Credits;

use InvalidArgumentException;

final class CreditReservation
{
    private const MAX_UNITS = 2147483647;
    private const STATUSES = ['reserved', 'consumed', 'refunded'];

    private int $id;
    private int $userId;
    private int $projectId;
    private int $units;
    private string $status;

    public function __construct(int $id, int $userId, int $projectId, int $units, string $status)
    {
        if ($id < 1 || $userId < 1 || $projectId < 1
            || $units < 1 || $units > self::MAX_UNITS
            || !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Credit reservation is invalid.');
        }

        $this->id = $id;
        $this->userId = $userId;
        $this->projectId = $projectId;
        $this->units = $units;
        $this->status = $status;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function projectId(): int
    {
        return $this->projectId;
    }

    public function units(): int
    {
        return $this->units;
    }

    public function status(): string
    {
        return $this->status;
    }
}
