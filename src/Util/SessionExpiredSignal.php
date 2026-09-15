<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Der Merker "die Sitzung ist abgelaufen" für Aufrufe, die JSON erwarten.
 *
 * Ein `fetch`-Aufruf kann mit einer Weiterleitung nichts anfangen: Er folgt ihr
 * selbst, bekommt die Anmeldeseite als HTML mit Status 200 und scheitert erst
 * beim Auswerten - die Oberfläche meldete dann "Speichern fehlgeschlagen",
 * obwohl nur die Sitzung abgelaufen war. Deshalb bekommt ein solcher Aufruf
 * hier einen Fehler als Fehler.
 *
 * Der Merker steht im Kopf der Antwort und nicht im Rumpf, weil die Oberfläche
 * ihn auswerten muss, ohne den Rumpf anzufassen: Ein Rumpf lässt sich nur
 * einmal lesen, und danach fehlte er dem eigentlichen Aufrufer.
 *
 * Zwei Stellen setzen ihn, und welche greift, hängt an der Methode:
 * Ein GET wird von der AuthMiddleware abgewiesen (401), ein POST erreicht sie
 * gar nicht erst - die CsrfMiddleware läuft davor, und mit der Sitzung ist auch
 * der Token weg (403). Der Merker ist an beiden Stellen derselbe, damit die
 * Oberfläche nicht nach Status unterscheiden muss.
 */
final class SessionExpiredSignal
{
    public const HEADER = 'X-Session-Expired';

    public const MESSAGE = 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.';

    /**
     * Setzt Merker, Inhaltstyp und Rumpf auf eine bereits mit dem passenden
     * Status gebaute Antwort.
     *
     * `error` und `message` tragen denselben Text - die Oberfläche liest je nach
     * Aufrufstelle das eine oder das andere (newsletters.js bzw. users.js), und
     * so muss kein Aufrufer angepasst werden.
     */
    public static function fill(Response $response): Response
    {
        $response->getBody()->write((string) json_encode([
            'error' => self::MESSAGE,
            'message' => self::MESSAGE,
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(self::HEADER, '1');
    }
}
