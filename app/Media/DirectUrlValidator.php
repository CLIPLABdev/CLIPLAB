<?php

declare(strict_types=1);

namespace App\Media;

use App\Exceptions\MediaValidationException;

final class DirectUrlValidator
{
    /** @var \Closure(string): array */
    private \Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? static fn (string $host): array => self::resolve($host) : \Closure::fromCallable($resolver);
    }

    public function validate(string $url): ValidatedRemoteUrl
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw MediaValidationException::withCode('unsafe_source_url');
        }
        if (isset($parts['fragment'])) {
            throw MediaValidationException::withCode('unsafe_source_url');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || str_contains($host, '%')) {
            throw MediaValidationException::withCode('unsafe_source_url');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if (!is_array($addresses) || $addresses === []) {
            throw MediaValidationException::withCode('unsafe_source_url');
        }
        $validated = [];
        foreach ($addresses as $address) {
            if (!is_string($address) || !self::isPublicAddress($address)) {
                throw MediaValidationException::withCode('unsafe_source_url');
            }
            $validated[] = $address;
        }

        return new ValidatedRemoteUrl($url, $host, array_values(array_unique($validated)));
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
        return $addresses;
    }

    private static function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return !self::inCidr($packed, '0.0.0.0', 8) && !self::inCidr($packed, '10.0.0.0', 8)
                && !self::inCidr($packed, '100.64.0.0', 10) && !self::inCidr($packed, '127.0.0.0', 8)
                && !self::inCidr($packed, '169.254.0.0', 16) && !self::inCidr($packed, '172.16.0.0', 12)
                && !self::inCidr($packed, '192.0.0.0', 24) && !self::inCidr($packed, '192.0.2.0', 24)
                && !self::inCidr($packed, '192.168.0.0', 16) && !self::inCidr($packed, '198.18.0.0', 15)
                && !self::inCidr($packed, '198.51.100.0', 24) && !self::inCidr($packed, '203.0.113.0', 24)
                && !self::inCidr($packed, '224.0.0.0', 4) && !self::inCidr($packed, '240.0.0.0', 4);
        }
        return !self::inCidr($packed, '::', 96) && !self::inCidr($packed, '::ffff:0:0', 96)
            && !self::inCidr($packed, '64:ff9b::', 96)
            && !self::inCidr($packed, '64:ff9b:1::', 48) && !self::inCidr($packed, '100::', 64)
            && !self::inCidr($packed, '2001::', 23) && !self::inCidr($packed, '2001:10::', 28)
            && !self::inCidr($packed, '2001:20::', 28) && !self::inCidr($packed, '2001:db8::', 32)
            && !self::inCidr($packed, '2002::', 16) && !self::inCidr($packed, '3ffe::', 16)
            && !self::inCidr($packed, '5f00::', 16) && !self::inCidr($packed, 'fc00::', 7)
            && !self::inCidr($packed, 'fe80::', 10) && !self::inCidr($packed, 'fec0::', 10)
            && !self::inCidr($packed, 'ff00::', 8);
    }

    private static function inCidr(string $packedAddress, string $network, int $prefix): bool
    {
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($bytes > 0 && substr($packedAddress, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($packedAddress[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }
}
