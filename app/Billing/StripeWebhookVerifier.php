<?php

declare(strict_types=1);

namespace App\Billing;

final class StripeWebhookVerifier
{
    /** @var callable():int */
    private $clock;

    /** @param callable():int|null $clock */
    public function __construct(private string $secret, private int $toleranceSeconds = 300, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function verify(string $rawBody, string $header): bool
    {
        if ($this->secret === '' || $rawBody === '' || $header === '' || $this->toleranceSeconds < 1) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }
            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || abs(($this->clock)() - $timestamp) > $this->toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
