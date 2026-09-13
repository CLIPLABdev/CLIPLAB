<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\CurlDownloadTransport;
use App\Media\DirectUrlValidator;
use App\Media\DownloadRequest;
use PHPUnit\Framework\TestCase;

final class CurlDownloadTransportOptionsTest extends TestCase
{
    public function testPinsValidatedAddressesAndDisablesAllProxyAndRedirectPaths(): void
    {
        if (!defined('CURLOPT_FOLLOWLOCATION')) {
            self::markTestSkipped('The cURL extension is not available.');
        }
        $url = (new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1', '2606:4700:4700::1111']))
            ->validate('https://cdn.example.test:8443/video.mp4');
        $options = (new CurlDownloadTransport())->optionsFor(
            new DownloadRequest($url, 7, 1024),
            static function (...$args): int { return 0; },
            static function (...$args): int { return 0; }
        );

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertFalse($options[CURLOPT_HTTPPROXYTUNNEL]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(['cdn.example.test:8443:1.1.1.1,[2606:4700:4700::1111]'], $options[CURLOPT_RESOLVE]);
    }

    /** @dataProvider validatedAddressSets */
    public function testKeepsAllValidatedAddressesInOneDnsCacheEntry(array $addresses, string $expectedResolve): void
    {
        if (!defined('CURLOPT_RESOLVE')) {
            self::markTestSkipped('The cURL extension is not available.');
        }
        $url = (new DirectUrlValidator(static fn (string $host): array => $addresses))
            ->validate('https://cdn.example.test/video.mp4');
        $options = (new CurlDownloadTransport())->optionsFor(
            new DownloadRequest($url, 7, 1024),
            static function (...$args): int { return 0; },
            static function (...$args): int { return 0; }
        );

        self::assertSame([$expectedResolve], $options[CURLOPT_RESOLVE]);
    }

    public function validatedAddressSets(): array
    {
        return [
            'multiple IPv4 addresses' => [
                ['1.1.1.1', '8.8.8.8'],
                'cdn.example.test:443:1.1.1.1,8.8.8.8',
            ],
            'IPv6 before IPv4' => [
                ['2606:4700:4700::1111', '1.1.1.1'],
                'cdn.example.test:443:[2606:4700:4700::1111],1.1.1.1',
            ],
            'single IPv6 address' => [
                ['2606:4700:4700::1111'],
                'cdn.example.test:443:[2606:4700:4700::1111]',
            ],
        ];
    }
}
