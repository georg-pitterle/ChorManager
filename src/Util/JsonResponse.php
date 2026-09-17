<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Schreibt eine JSON-Antwort mit Kopfzeile und Status.
 *
 * Denselben Fünfzeiler tragen mehrere Controller als eigene private Methode.
 * Hier stehen bislang die beiden Ingest-Endpunkte, deren Doppelung der
 * Review-Lauf 21 benannt hat; NewsletterController, NewsletterTemplateController,
 * ProfileController und RegistrationController haben ihre eigenen Fassungen
 * behalten. Die unterscheiden sich in einem Punkt - zwei von ihnen kodieren ohne
 * JSON_UNESCAPED_UNICODE und schicken Umlaute als \\u00e4 - und sie umzustellen
 * ändert die Bytes auf der Leitung von vier weiteren Endpunkten. Das ist eine
 * eigene Entscheidung und kein Nebenprodukt dieser hier.
 */
final class JsonResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function write(Response $response, array $payload, int $statusCode = 200): Response
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($encoded === false ? '{}' : $encoded);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }
}
