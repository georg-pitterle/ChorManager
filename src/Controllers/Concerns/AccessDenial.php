<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Die Abweisung mit sichtbarer Begründung: 403 mit Körper, dazu die
 * Protokollzeile `authz.denied`.
 *
 * Warum überhaupt eine Seite: Ein 403 mit Location-Header führt zu einer leeren
 * Antwort, weil Browser nur 3xx-Weiterleitungen folgen - und ein 403 ganz ohne
 * Körper ebenfalls. Die Flash-Meldung bekam dabei nie eine Seite, auf der sie
 * erscheinen konnte.
 *
 * Warum hier: Dieselben zwei Anweisungen - Protokollzeile und Rendern von
 * `errors/403.twig` mit Status 403 - standen in sechs Controllern, bei dreien
 * Wort für Wort gleich samt Kommentar (Antwort aus Review-Lauf 84). Der Name
 * des Ereignisses und die Fehlerseite gehören an eine Stelle; was je Controller
 * verschieden ist, ist allein der Protokoll-Kontext, und der kommt als
 * Parameter. Die Controller behalten ihre eigene kurze Methode darüber - sie
 * legt die Form ihres Kontexts fest (`event_id`, `project_id`) und hält die
 * Aufrufstellen lesbar.
 *
 * Erwartet von der benutzenden Klasse: `$this->view` (Slim\Views\Twig) und -
 * für denyAccess() - `$this->logger` (Psr\Log\LoggerInterface).
 */
trait AccessDenial
{
    /**
     * Abweisung samt Protokolleintrag. Der Kontext nennt, woran es lag: eine
     * `reason`-Kennung oder das fehlende `permission`-Recht, dazu die betroffene
     * Zeile.
     *
     * @param array<string, mixed> $context
     */
    private function denyAccess(Response $response, string $message, array $context): Response
    {
        $this->logger->info('Access denied.', ['event' => 'authz.denied'] + $context);

        return $this->forbidden($response, $message);
    }

    /**
     * Dieselbe Seite ohne Protokolleintrag - für fachliche Sperren. Ein
     * abgelaufener Anmeldeschluss ist kein Autorisierungsvorfall und hat im
     * Audit-Log nichts verloren.
     */
    private function forbidden(Response $response, string $message): Response
    {
        return $this->view->render($response->withStatus(403), 'errors/403.twig', [
            'error' => $message,
        ]);
    }
}
