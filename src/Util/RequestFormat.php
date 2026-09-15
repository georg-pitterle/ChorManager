<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Erwartet der Aufrufer eine JSON-Antwort?
 *
 * Die Frage stellen die CsrfMiddleware, die RoleMiddleware und mehrere
 * Controller - und jede Stelle trug bis hierher ihre eigene Abschrift derselben
 * sechs Zeilen. Die Kommentare dort erklärten alle dasselbe: Die Oberfläche
 * schickt je nach Aufrufstelle nur `X-Requested-With` (etwa newsletters-edit.js)
 * oder zusätzlich `Accept` (etwa registrations.js), beide Formen müssen zählen.
 *
 * Fünf Abschriften heißen fünf Stellen, an denen eine Korrektur vergessen werden
 * kann - und die Antwort muss überall dieselbe sein: Eine Middleware, die eine
 * Weiterleitung schickt, wo der Controller JSON geschickt hätte, liefert dem
 * `fetch`-Aufruf die Anmeldeseite als HTML mit Status 200, und der Fehler, den
 * die Person sieht, hat mit der Ursache nichts mehr zu tun.
 */
final class RequestFormat
{
    public static function expectsJson(Request $request): bool
    {
        if (strtolower(trim($request->getHeaderLine('X-Requested-With'))) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }
}
