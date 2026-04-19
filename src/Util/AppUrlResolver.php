<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface as Request;

final class AppUrlResolver
{
    public static function resolveBaseUrl(Request $request): string
    {
        $configured = trim((string) EnvHelper::read('APP_URL', ''));
        $configuredUrl = self::normalizeConfiguredUrl($configured);
        if ($configuredUrl !== null) {
            return $configuredUrl;
        }

        $ddevUrl = self::resolveDdevUrl();
        if ($ddevUrl !== null) {
            return $ddevUrl;
        }

        return self::resolveFromRequest($request);
    }

    private static function normalizeConfiguredUrl(string $configured): ?string
    {
        if ($configured === '') {
            return null;
        }

        $parts = parse_url($configured);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;
        $path = is_array($parts) ? trim((string) ($parts['path'] ?? '')) : '';

        if (($scheme === 'http' || $scheme === 'https') && $host !== '') {
            $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
            $portPart = ($port !== null && !$isDefaultPort) ? ':' . $port : '';
            $normalizedPath = $path === '' ? '' : rtrim($path, '/');

            return $scheme . '://' . $host . $portPart . $normalizedPath;
        }

        return null;
    }

    private static function resolveDdevUrl(): ?string
    {
        $withoutPort = trim((string) EnvHelper::read('DDEV_PRIMARY_URL_WITHOUT_PORT', ''));
        $normalizedWithoutPort = self::normalizeConfiguredUrl($withoutPort);
        if ($normalizedWithoutPort !== null) {
            return $normalizedWithoutPort;
        }

        $primaryUrl = trim((string) EnvHelper::read('DDEV_PRIMARY_URL', ''));
        return self::normalizeConfiguredUrl($primaryUrl);
    }

    private static function resolveFromRequest(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'http';
        }

        $host = trim((string) $uri->getHost());
        $port = $uri->getPort();

        $remote = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $isTrustedProxy = $remote !== '' && ClientIpResolver::isTrustedProxy($remote);

        if ($isTrustedProxy) {
            $forwarded = self::parseForwardedHeader($request->getHeaderLine('Forwarded'));

            $xForwardedProto = self::firstHeaderValue($request->getHeaderLine('X-Forwarded-Proto'));
            $xForwardedHost = self::firstHeaderValue($request->getHeaderLine('X-Forwarded-Host'));
            $xForwardedPort = self::firstHeaderValue($request->getHeaderLine('X-Forwarded-Port'));

            $candidateProto = strtolower($xForwardedProto !== '' ? $xForwardedProto : ($forwarded['proto'] ?? ''));
            if ($candidateProto === 'http' || $candidateProto === 'https') {
                $scheme = $candidateProto;
            }

            $candidateHost = $xForwardedHost !== '' ? $xForwardedHost : ($forwarded['host'] ?? '');
            if ($candidateHost !== '') {
                [$parsedHost, $parsedPort] = self::splitHostAndPort($candidateHost);
                if ($parsedHost !== '') {
                    $host = $parsedHost;
                }
                if ($parsedPort !== null) {
                    $port = $parsedPort;
                }
            }

            if ($xForwardedPort !== '' && ctype_digit($xForwardedPort)) {
                $port = (int) $xForwardedPort;
            }
        }

        if ($host === '') {
            return 'http://localhost';
        }

        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $portPart = ($port !== null && !$isDefaultPort) ? ':' . $port : '';

        return $scheme . '://' . $host . $portPart;
    }

    /**
     * @return array{proto?: string, host?: string}
     */
    private static function parseForwardedHeader(string $header): array
    {
        $header = trim($header);
        if ($header === '') {
            return [];
        }

        $firstElement = trim(explode(',', $header, 2)[0]);
        if ($firstElement === '') {
            return [];
        }

        $result = [];
        foreach (explode(';', $firstElement) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $part, 2);
            $name = strtolower(trim($name));
            $value = trim($value, " \t\n\r\0\x0B\"");
            if ($name === 'proto' || $name === 'host') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private static function firstHeaderValue(string $header): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }

        return trim((string) explode(',', $header, 2)[0]);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function splitHostAndPort(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', null];
        }

        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            if ($end === false) {
                return [$value, null];
            }

            $host = substr($value, 0, $end + 1);
            $rest = trim(substr($value, $end + 1));
            if (str_starts_with($rest, ':') && ctype_digit(substr($rest, 1))) {
                return [$host, (int) substr($rest, 1)];
            }

            return [$host, null];
        }

        if (substr_count($value, ':') === 1) {
            [$host, $portCandidate] = explode(':', $value, 2);
            if (ctype_digit($portCandidate)) {
                return [trim($host), (int) $portCandidate];
            }
        }

        return [$value, null];
    }
}
