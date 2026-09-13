<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\StripeWebhookVerifier;
use PHPUnit\Framework\TestCase;

final class BillingWebhookVerifierTest extends TestCase
{
    public function testStripeVerifierRequiresARecentMatchingV1Signature(): void
    {
        if (!class_exists(StripeWebhookVerifier::class)) {
            self::fail('Stripe webhook verifier is not available.');
        }

        $body = '{"id":"evt_42","type":"checkout.session.completed"}';
        $timestamp = 1700000000;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');
        $verifier = new StripeWebhookVerifier('whsec_test', 300, static fn (): int => $timestamp + 30);

        self::assertTrue($verifier->verify($body, 't=' . $timestamp . ',v1=' . $signature));
        self::assertFalse($verifier->verify($body, 't=' . $timestamp . ',v1=not-a-match'));
        self::assertFalse($verifier->verify($body, 't=' . ($timestamp - 301) . ',v1=' . $signature));
    }
}
