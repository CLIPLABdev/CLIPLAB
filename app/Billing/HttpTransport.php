<?php
declare(strict_types=1);
namespace App\Billing;

interface HttpTransport
{
    /** @return array<string,mixed> Provider JSON; throws a sanitized RuntimeException on failure. */
    public function request(string $method, string $url, array $headers, array $body = []): array;
}
