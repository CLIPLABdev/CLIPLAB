<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use PHPUnit\Framework\TestCase;

final class DirectUrlHardeningTest extends TestCase
{
    /** @dataProvider specialIpv6 */
    public function testRejectsSpecialIpv6RangesByDefault(string $address): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => [$address]);
        $this->expectException(MediaValidationException::class);
        $validator->validate('https://cdn.example.test/video.mp4');
    }

    /** @return iterable<string, array{string}> */
    public function specialIpv6(): iterable
    {
        yield 'ipv4 compatible loopback' => ['::127.0.0.1'];
        yield 'nat64 well known' => ['64:ff9b::c000:201'];
        yield '6to4 private embedded' => ['2002:0a00:0001::'];
        yield 'site local' => ['fec0::1'];
        yield 'benchmark' => ['2001:2::1'];
        yield 'discard only' => ['100::1'];
    }
}
