<?php

declare(strict_types=1);

namespace App\Util;

/**
 * SSRF guard for user-supplied outbound connection targets (e.g. an IMAP host
 * entered in the mailbox settings).
 *
 * The threat: an authenticated user could point a "host" field at an internal
 * service (127.0.0.1, 10.0.0.0/8, the 169.254.169.254 cloud-metadata endpoint,
 * IPv6 loopback/link-local, ...) and use the server as a proxy/port scanner.
 *
 * resolvePublicIp() resolves the host once, rejects it if ANY resolved address
 * is non-public, and returns a single validated IP literal. Callers must then
 * connect to that exact IP (pinning) while setting the TLS peer_name to the
 * original hostname - this closes the DNS-rebinding window between the check
 * and the connect, because the kernel never re-resolves the name.
 *
 * Fails closed: resolution failure or any non-public address throws
 * BlockedHostException. An operator running ChorManager on the same private
 * network as the mail server can opt out via MAIL_ALLOW_PRIVATE_HOSTS=1.
 */
final class OutboundConnectionGuard
{
    private const ALLOW_PRIVATE_ENV = 'MAIL_ALLOW_PRIVATE_HOSTS';

    /**
     * Resolve $host and return one validated IP literal safe to connect to.
     *
     * @throws BlockedHostException on empty input, resolution failure, or a
     *     non-public resolved address.
     */
    public static function resolvePublicIp(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            throw new BlockedHostException('empty host');
        }

        $allowPrivate = EnvHelper::readBool(self::ALLOW_PRIVATE_ENV, false);

        $literal = $host;
        if (str_starts_with($literal, '[') && str_ends_with($literal, ']')) {
            $literal = substr($literal, 1, -1);
        }

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $normalised = (string) inet_ntop((string) inet_pton($literal));
            if (!$allowPrivate) {
                self::assertPublicIp($normalised);
            }

            return $normalised;
        }

        $ips = self::resolveHost($host);
        if ($ips === []) {
            throw new BlockedHostException('host did not resolve: ' . $host);
        }

        if (!$allowPrivate) {
            // Reject the whole host if ANY resolved address is non-public, so a
            // name that returns both a public and a private record cannot be
            // used to smuggle traffic to the private one.
            foreach ($ips as $ip) {
                self::assertPublicIp($ip);
            }
        }

        return $ips[0];
    }

    /**
     * @throws BlockedHostException when $ip is not a public, routable address.
     */
    private static function assertPublicIp(string $ip): void
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new BlockedHostException('unparseable address');
        }

        // Unwrap IPv4-mapped IPv6 (::ffff:a.b.c.d) and validate the embedded v4.
        if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $mapped = inet_ntop(substr($packed, 12));
            if ($mapped !== false) {
                $ip = $mapped;
                $packed = (string) inet_pton($ip);
            }
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($isPublic === false) {
            throw new BlockedHostException('non-public address: ' . $ip);
        }

        // Explicit IPv6 hardening: the built-in range flags are inconsistent
        // across PHP versions for loopback/link-local/unique-local.
        if (strlen($packed) === 16) {
            $byte0 = ord($packed[0]);
            $byte1 = ord($packed[1]);

            if ($packed === str_repeat("\x00", 15) . "\x01") {
                throw new BlockedHostException('non-public address: ::1');
            }

            if (($byte0 & 0xFE) === 0xFC) { // fc00::/7 unique local
                throw new BlockedHostException('non-public address: ' . $ip);
            }

            if ($byte0 === 0xFE && ($byte1 & 0xC0) === 0x80) { // fe80::/10 link local
                throw new BlockedHostException('non-public address: ' . $ip);
            }
        }
    }

    /**
     * @return list<string> Unique resolved IPv4/IPv6 literals (may be empty).
     */
    private static function resolveHost(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }
}
