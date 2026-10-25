<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Die Seite, von der abgeschickt wurde, als sicheres Ziel innerhalb der Anwendung -
 * oder null.
 *
 * Der Pfad der abgewiesenen Anfrage taugt dafür nicht: Zu einem POST-Ziel wie
 * `/profile/password` gehört gar keine Seite. Der Rückverweis des Browsers nennt
 * dagegen genau die Seite, von der abgeschickt wurde - bei gleicher Herkunft schickt
 * ihn der Browser vollständig mit, dafür sorgt die `Referrer-Policy` aus der
 * SecurityHeadersMiddleware.
 *
 * Geprüft wird er doppelt: Die Herkunft muss die eigene sein, und der übrige Pfad
 * muss `SafeRedirect` bestehen. Sonst wäre die Weiterleitung über einen gefälschten
 * Rückverweis ein offener Weiterleiter - der Rückverweis kommt von außen, und ein
 * fremder Absender setzt sein eigenes `Referrer-Policy: unsafe-url` und bestimmt den
 * Pfad darin frei.
 *
 * Lässt sich die Herkunft nicht vergleichen - kein Host in der Anfrage, oder ein
 * Proxy, der den Host-Kopf umschreibt -, gibt es kein Ziel. Das ist eine
 * Unbequemlichkeit, kein Schaden.
 *
 * Steht hier und nicht in der Middleware, weil zwei Stellen dasselbe brauchen: die
 * CsrfMiddleware nach einer abgewiesenen Absendung und die UploadFileCountMiddleware
 * nach einem verworfenen Upload. Beide hatten eine eigene Abschrift, und die zweite
 * prüfte schwächer - sie ließ einen Pfad wie `/\fremder.host` durch, aus dem der
 * Browser im `Location`-Kopf `//fremder.host` macht. Genau diese Form fängt
 * `SafeRedirect` ab.
 */
final class RefererTarget
{
    public static function resolve(Request $request): ?string
    {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return null;
        }

        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return null;
        }

        $refererHost = strtolower((string) ($parts['host'] ?? ''));
        if ($refererHost !== strtolower($request->getUri()->getHost())) {
            return null;
        }

        $target = (string) ($parts['path'] ?? '');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $target .= '?' . $parts['query'];
        }

        return SafeRedirect::sanitize($target);
    }
}
