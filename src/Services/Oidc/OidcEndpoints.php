<?php

declare(strict_types=1);

namespace App\Services\Oidc;

/**
 * Die Pfade des Providers an einer Stelle.
 *
 * Sie stehen in drei Zusammenhängen, die zueinander passen müssen: in
 * src/Routes.php, im Discovery-Dokument und in der CSRF-Ausnahme für den
 * Token-Endpunkt. Liefe eine davon auseinander, meldete Nextcloud nur einen
 * unbrauchbaren Fehler - die Konstanten machen das unmöglich.
 */
final class OidcEndpoints
{
    public const DISCOVERY = '/.well-known/openid-configuration';
    public const JWKS = '/oidc/jwks.json';
    public const AUTHORIZE = '/oidc/authorize';
    public const TOKEN = '/oidc/token';
    public const USERINFO = '/oidc/userinfo';
    public const LOGOUT = '/oidc/logout';
}
