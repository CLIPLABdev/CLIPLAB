<?php

declare(strict_types=1);

namespace App\Contracts;

interface JobDispatcher
{
    public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int;
}
