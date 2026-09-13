<?php
declare(strict_types=1);
namespace App\Billing;

interface GatewayAdapter
{
    public function create(array $attempt, array $quote): array;
    /** Fetches and validates authoritative subscription + invoice; callback fields are identifiers only. */
    public function confirm(array $attempt, array $quote, string $objectId, string $eventType): array;
}
