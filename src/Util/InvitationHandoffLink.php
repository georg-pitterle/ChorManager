<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Optionaler Übergabe-Link ("Handoff"), der in der Einladungs-E-Mail angezeigt wird.
 *
 * Der Link zeigt auf eine externe Ressource (z. B. Onboarding-Dokument im Vereins-Wiki)
 * und wird ausschließlich über die .env konfiguriert. Ist keine URL gesetzt, enthält die
 * Einladungs-E-Mail keinen zusätzlichen Link.
 */
class InvitationHandoffLink
{
    public const ENV_URL = 'INVITATION_HANDOFF_URL';
    public const ENV_LABEL = 'INVITATION_HANDOFF_LABEL';
    public const DEFAULT_LABEL = 'Erste Schritte';

    /**
     * Liefert den konfigurierten Übergabe-Link oder null, wenn keiner gesetzt oder die URL unsicher ist.
     *
     * @return array{url: string, label: string}|null
     */
    public static function resolve(): ?array
    {
        $url = EnvHelper::read(self::ENV_URL);
        if ($url === '' || !self::isSafeUrl($url)) {
            return null;
        }

        return [
            'url' => $url,
            'label' => EnvHelper::read(self::ENV_LABEL, self::DEFAULT_LABEL),
        ];
    }

    /**
     * True, wenn eine URL konfiguriert ist, diese aber verworfen wurde (Fehlkonfiguration).
     */
    public static function isMisconfigured(): bool
    {
        $url = EnvHelper::read(self::ENV_URL);

        return $url !== '' && !self::isSafeUrl($url);
    }

    /**
     * Nur absolute http(s)-URLs sind zulässig; damit sind u. a. javascript:- und data:-URLs ausgeschlossen.
     */
    private static function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
