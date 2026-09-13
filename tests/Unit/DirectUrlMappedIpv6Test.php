<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use PHPUnit\Framework\TestCase;

final class DirectUrlMappedIpv6Test extends TestCase
{
    /** @dataProvider mappedIpv4Addresses */
    public function testRejectsEveryIpv4MappedIpv6AddressConservatively(string $address): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => [$address]);

        try {
            $validator->validate('https://cdn.example.test/video.mp4');
            self::fail('IPv4-mapped IPv6 addresses are not accepted, even when their mapped IPv4 value appears public.');
        } catch (MediaValidationException $exception) {
            self::assertSame('unsafe_source_url', $exception->publicCode());
        }
    }

    /** @return iterable<string, array{string}> */
    public function mappedIpv4Addresses(): iterable
    {
        yield 'mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'mapped private address' => ['::ffff:10.0.0.1'];
        yield 'mapped public address' => ['::ffff:8.8.8.8'];
    }
}
