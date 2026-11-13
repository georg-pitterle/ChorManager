<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcClient;

/**
 * Die Ursprünge, an die das Login-Formular nach der Anmeldung weiterleiten darf.
 *
 * Wer sich für eine angeschlossene Anwendung anmeldet, schickt das Login-Formular
 * ab und landet über /oidc/authorize bei deren Rücksprungadresse. Chrome prüft
 * form-action auch auf diese Weiterleitungen - mit 'self' allein bricht die Kette
 * an der fremden Adresse ab. Freigegeben werden deshalb genau die Ursprünge der
 * Anwendungen, an die tatsächlich angemeldet wird: aktiv und vertrauenswürdig, wie
 * OidcClientService::isUsableForLogin() es verlangt.
 */
class OidcRedirectOrigins
{
    /**
     * Schema, Host und Port - mehr darf in der CSP nicht stehen. Was nicht in diese
     * Form passt, bleibt draußen, damit keine eingetragene Adresse die Kopfzeile
     * aufbrechen kann.
     */
    private const ORIGIN_PATTERN = '#^https?://[a-z0-9.-]+(:\d{1,5})?$#';

    /**
     * @return list<string>
     */
    public function forLoginForm(): array
    {
        $origins = [];

        $clients = OidcClient::query()
            ->where('is_active', true)
            ->where('is_trusted', true)
            ->get();

        foreach ($clients as $client) {
            foreach ($client->redirectUris() as $uri) {
                $origin = self::originOf($uri);
                if ($origin !== null) {
                    $origins[$origin] = true;
                }
            }
        }

        $list = array_keys($origins);
        sort($list);

        return $list;
    }

    private static function originOf(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return preg_match(self::ORIGIN_PATTERN, $origin) === 1 ? $origin : null;
    }
}
