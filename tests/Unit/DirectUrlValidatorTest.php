<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use PHPUnit\Framework\TestCase;

final class DirectUrlValidatorTest extends TestCase
{
    public function testRejectsPrivateCredentialedAndNonHttpsUrls(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);

        foreach (['https://127.0.0.1/video.mp4', 'https://user:pass@example.com/video.mp4', 'http://example.com/video.mp4'] as $url) {
            try {
                $validator->validate($url);
                self::fail('Unsafe URL accepted: ' . $url);
            } catch (MediaValidationException $exception) {
                self::assertSame('unsafe_source_url', $exception->publicCode());
            }
        }
    }

    /** @dataProvider nonPublicAddresses */
    public function testRejectsEveryPrivateOrReservedDnsAnswer(string $address): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1', $address]);

        try {
            $validator->validate('https://media.example.test/video.mp4');
            self::fail('A DNS response containing a non-public address must fail closed.');
        } catch (MediaValidationException $exception) {
            self::assertSame('unsafe_source_url', $exception->publicCode());
        }
    }

    public function testReturnsTheOriginalHostAndOnlyPinnedPublicAddresses(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1', '2606:4700:4700::1111']);

        $validated = $validator->validate('https://cdn.example.test:8443/path/video.mp4?quality=high');

        self::assertSame('cdn.example.test', $validated->host());
        self::assertSame(['1.1.1.1', '2606:4700:4700::1111'], $validated->addresses());
        self::assertSame('https://cdn.example.test:8443/path/video.mp4?quality=high', $validated->url());
    }

    /** @return iterable<string, array{string}> */
    public function nonPublicAddresses(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1'];
        yield 'ipv4 link local' => ['169.254.169.254'];
        yield 'ipv4 private' => ['10.0.0.1'];
        yield 'ipv4 cgnat' => ['100.64.0.1'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 link local' => ['fe80::1'];
        yield 'ipv6 unique local' => ['fd00::1'];
        yield 'ipv6 documentation' => ['2001:db8::1'];
    }
}
