<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Die drei Adressen der Office-Anbindung. Browser, ChorManager und Collabora
 * erreichen einander je nach Betrieb unter verschiedenen Namen - in DDEV etwa
 * spricht Collabora ChorManager als `http://web` an, der Browser Collabora aber
 * über den Router.
 */
final class OfficeSettings
{
    public function __construct(
        public readonly string $serverUrl,
        public readonly string $internalUrl,
        public readonly string $wopiBaseUrl,
        public readonly string $appUrl
    ) {
    }

    /**
     * @param array<string, mixed> $office settings['office']
     */
    public static function fromArray(array $office, string $appUrl): self
    {
        $server = rtrim(trim((string) ($office['server_url'] ?? '')), '/');
        $internal = rtrim(trim((string) ($office['internal_url'] ?? '')), '/');
        $wopi = rtrim(trim((string) ($office['wopi_base_url'] ?? '')), '/');
        $app = rtrim(trim($appUrl), '/');

        return new self($server, $internal !== '' ? $internal : $server, $wopi !== '' ? $wopi : $app, $app);
    }

    /** Ursprung des Office-Servers - für CSP und die Prüfung von PostMessages. */
    public function serverOrigin(): string
    {
        return self::originOf($this->serverUrl);
    }

    /** Ursprung von ChorManager im Browser - Collabora schickt PostMessages nur dorthin. */
    public function appOrigin(): string
    {
        return self::originOf($this->appUrl);
    }

    public static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
