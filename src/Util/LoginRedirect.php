<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

/**
 * Die Abweisung einer nicht (mehr) angemeldeten Anfrage.
 *
 * Zwei Reihen weisen sie ab: die AuthMiddleware an der geschützten Gruppe und die
 * RoleMiddleware an der einzelnen Route. Die zweite ist im Regelbetrieb nicht
 * erreichbar - genau deshalb muss sie dasselbe tun wie die erste. Eine zweite Reihe,
 * die anders antwortet als die erste, fällt erst auf, wenn sie einmal greift, und
 * gleich war bisher nur die JSON-Antwort: Für den Browser schickte die erste Reihe
 * das gewünschte Ziel mit, die zweite ließ es weg.
 *
 * Für den Browser eine Weiterleitung mit Ziel, für einen `fetch`-Aufruf ein 401 mit
 * JSON: Der folgt einer Weiterleitung selbst, bekommt die Anmeldeseite als HTML mit
 * Status 200 und scheitert erst beim Auswerten - die Oberfläche meldete dann
 * "Speichern fehlgeschlagen", was mit der abgelaufenen Sitzung nichts zu tun hat.
 */
final class LoginRedirect
{
    /**
     * Die fertige Antwort - 401 mit Merker für JSON-Aufrufe, sonst die Weiterleitung.
     */
    public static function respond(Request $request): Response
    {
        if (RequestFormat::expectsJson($request)) {
            return SessionExpiredSignal::fill(new SlimResponse(401));
        }

        return (new SlimResponse())
            ->withHeader('Location', self::location($request))
            ->withStatus(302);
    }

    /**
     * Wohin die Weiterleitung zeigt.
     *
     * Das Ziel trägt nur ein GET: Zu einem Aufrufziel wie `/users/42/roles` gehört
     * keine Seite, die man nach der Anmeldung aufrufen könnte.
     *
     * `/dashboard` ist ohnehin das Ziel nach der Anmeldung, und ein `redirect` auf das
     * Anmeldeformular selbst schickte die Person nach erfolgreicher Anmeldung dorthin
     * zurück. Beide Ziele tragen deshalb keinen Parameter.
     *
     * Durch `SafeRedirect` läuft der Pfad trotz eigener Herkunft: Er stammt aus der
     * Anfragezeile und kann damit auch `//fremder.host/pfad` lauten. Eine Lücke war
     * das nie - `AuthController` prüft den Parameter beim Einlösen -, aber die
     * Prüfung gehört an beide Enden, nicht nur an eines.
     */
    public static function location(Request $request): string
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return '/login';
        }

        $target = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        $safeTarget = SafeRedirect::sanitize($target);
        if ($safeTarget === null || $safeTarget === '/login' || $safeTarget === '/dashboard') {
            return '/login';
        }

        return '/login?redirect=' . rawurlencode($safeTarget);
    }
}
