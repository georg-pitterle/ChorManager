<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die eine Stelle, die APP_ENV auswertet.
 *
 * Vier Stellen lasen die Variable vorher selbst, und alle vier taten es anders als
 * hier: `EnvHelper::read('APP_ENV', 'development') === 'production'`, also mit der
 * Vorgabe "development" und buchstabengenau. Fehlte die Variable - oder stand sie
 * als "Production" -, dann galt dieselbe Installation hier als Produktivbetrieb und
 * dort als Entwicklung. Abgeschaltet waren damit still genau die Schutzmaßnahmen,
 * die man im Produktivbetrieb braucht: HSTS (SecurityHeadersMiddleware), das
 * Secure-Flag am Angemeldet-bleiben-Cookie (RememberLoginService), die Weigerung,
 * SMTP-Zugangsdaten im Klartext zu schicken (Mailer), und der Startabbruch ohne
 * APP_URL (AppUrlResolver). Sichtbar war davon nichts - die Anwendung lief.
 *
 * Deshalb fragt niemand mehr selbst. Wer wissen will, ob Produktivbetrieb läuft,
 * ruft `isProduction()`; `tests/Feature/SecurityHardeningFeatureTest` weist eine
 * neue Abschrift in `src/` ab (Nacharbeit zu Review-Lauf 82).
 */
final class AppEnvironment
{
    /**
     * Die Vorgabe ist "production", und das ist die vorsichtige Richtung: Eine
     * vergessene Variable lässt die Schutzmaßnahmen an, statt sie abzuschalten.
     * Kleingeschrieben wird verglichen, damit "Production" nicht am Vergleich
     * vorbeiläuft.
     */
    public static function current(): string
    {
        return strtolower(EnvHelper::read('APP_ENV', 'production'));
    }

    public static function isDebugEnabled(): bool
    {
        return in_array(self::current(), ['development', 'dev', 'local', 'test'], true);
    }

    public static function isProduction(): bool
    {
        return self::current() === 'production';
    }
}
