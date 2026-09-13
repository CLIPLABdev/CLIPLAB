<?php

declare(strict_types=1);

namespace App\Queue;

use DateTimeImmutable;

interface JobRepository
{
    public function claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob;
    public function failOneExpiredExhausted(string $queue): bool;
    public function complete(ClaimedJob $job): bool;
    public function retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool;
    public function defer(ClaimedJob $job, DateTimeImmutable $availableAt): bool;
    public function fail(ClaimedJob $job, string $code, string $publicMessage): bool;
}
