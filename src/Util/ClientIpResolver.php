<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface as Request;

final class ClientIpResolver
{
    public static function resolve(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $remote = trim((string) ($serverParams['REMOTE_ADDR'] ?? ''));

        if ($remote === '') {
            return 'unknown';
        }

        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = trim($request->getHeaderLine('X-Forwarded-For'));
        if ($forwarded === '') {
            return $remote;
        }

        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    public static function isTrustedProxy(string $remoteAddress): bool
    {
        $trusted = EnvHelper::read('TRUSTED_PROXIES', '');
        if ($trusted === '') {
            return false;
        }

        foreach (preg_split('/\s*,\s*/', $trusted) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            if (self::matchesTrustedProxy($remoteAddress, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesTrustedProxy(string $remoteAddress, string $trustedProxy): bool
    {
        if (!str_contains($trustedProxy, '/')) {
            return strcasecmp($remoteAddress, $trustedProxy) === 0;
        }

        [$subnet, $prefixLength] = explode('/', $trustedProxy, 2);
        if (filter_var($subnet, FILTER_VALIDATE_IP) === false || !ctype_digit($prefixLength)) {
            return false;
        }

        $prefix = (int) $prefixLength;
        if (
            filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            return self::matchesIpv4Cidr($remoteAddress, $subnet, $prefix);
        }

        if (
            filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            return self::matchesIpv6Cidr($remoteAddress, $subnet, $prefix);
        }

        return false;
    }

    private static function matchesIpv4Cidr(string $ip, string $subnet, int $prefix): bool
    {
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~((1 << (32 - $prefix)) - 1)) & 0xFFFFFFFF;
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function matchesIpv6Cidr(string $ip, string $subnet, int $prefix): bool
    {
        if ($prefix < 0 || $prefix > 128) {
            return false;
        }

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (
            (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask)
        );
    }
}
