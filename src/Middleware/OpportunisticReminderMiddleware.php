<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\AfterResponse;
use App\Util\AppUrlResolver;
use App\Util\OpportunisticRunGate;
use App\Util\OpportunisticWork;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

/**
 * Stößt fällige Erinnerungen nebenbei an, wenn kein Cron läuft.
 *
 * Zwei Middlewares hängen daran - die Anmelde-Erinnerung und die
 * Benachrichtigungs-Erinnerung -, und sie waren Zeile für Zeile dieselbe
 * Klasse: gleicher Stundentakt, gleiche Fabrik, gleicher `try`-Block, gleiche
 * Fehlerbehandlung. Verschieden waren nur der Merker-Schlüssel und der Name des
 * Log-Ereignisses. Zwei Abschriften derselben Regel heißen zwei Stellen, an
 * denen eine Korrektur vergessen werden kann; hier steht sie einmal, und die
 * beiden Ableitungen sagen nur noch, wofür sie zuständig sind.
 *
 * Die Wartezeit und die Betriebsart-Prüfung - eine Installation, die
 * `mailqueue_trigger_mode` auf reinen Cron stellt, will hier keine Arbeit im
 * Anfrageweg - liegen beide im `OpportunisticRunGate`.
 */
abstract class OpportunisticReminderMiddleware implements MiddlewareInterface
{
    protected const CHECK_INTERVAL_SECONDS = 3600;

    /**
     * Der Dienst kommt über eine Fabrik, nicht als fertige Instanz. Grund: Diese
     * Middlewares laufen global und damit vor der AuthMiddleware. Ihre Dienste
     * hängen an Twig, und Twig hier zu bauen fror den noch unangemeldeten
     * Sitzungszustand ein - eine per Remember-Me wiederhergestellte Anmeldung
     * erreichte die Templates nicht mehr, und die Navigationsleiste verschwand
     * für diese eine Anfrage.
     *
     * @param Closure(): object $reminderServiceFactory Liefert einen Dienst mit
     *        `processDue(string $baseUrl): int`.
     */
    public function __construct(
        private readonly Closure $reminderServiceFactory,
        private readonly LoggerInterface $logger,
        private readonly AfterResponse $afterResponse
    ) {
    }

    /**
     * Der Schlüssel in `app_settings`, unter dem der Zeitpunkt des letzten Laufs
     * steht. Er trennt die beiden Erinnerungen voneinander - mit einem
     * gemeinsamen Merker bremste die eine die andere aus.
     */
    abstract protected function markerKey(): string;

    /**
     * Der gemeinsame Stamm der beiden `event`-Schlüssel: daraus werden
     * "<stamm>.skipped" für eine Datenbank-Störung und "<stamm>.failed" für
     * jeden anderen Fehlschlag.
     *
     * Hier standen die beiden Schlüssel als eigene Methoden. Zwei Namen, die
     * sich nur in der letzten Silbe unterscheiden durften, sind zwei
     * Gelegenheiten, sie auseinanderlaufen zu lassen - und eine Ableitung mit
     * vertauschten Schlüsseln sähe von außen aus wie eine, die funktioniert.
     */
    abstract protected function eventPrefix(): string;

    /**
     * Gearbeitet wird erst, wenn die Antwort beim Browser und die Sitzung
     * freigegeben ist - genau wie bei der Mailwarteschlange.
     *
     * Vorher lief der Lauf vor dem Controller: Einmal pro Stunde wartete genau
     * die Person, deren Aufruf ihn auslöste, zusätzlich auf die Abfragen über
     * Aufgaben, Wiedervorlagen und Terminanmeldungen und auf das Aufräumen von
     * Versandprotokoll und Glocken-Einträgen. Niemand braucht dieses Ergebnis
     * für die Seite, die gerade gebaut wird - die Erinnerungen stellen nur in
     * die Warteschlange, verschickt wird daraus später.
     *
     * Der Merker wird damit ebenfalls erst nach der Antwort gesetzt. Das
     * verschiebt die Wartezeit um die Dauer einer Anfrage und sonst nichts:
     * `OpportunisticRunGate` setzt ihn weiterhin vor der Arbeit, zwei
     * gleichzeitige Anfragen bremsen sich also wie bisher.
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $this->afterResponse->defer(fn () => $this->processIfDue($request));

        return $response;
    }

    /**
     * Die Meldung ist für beide Erinnerungen dieselbe; unterschieden wird über
     * den `event`-Schlüssel, und der ist laut Logging-Standard ohnehin der
     * stabile Bezeichner einer Logzeile. Die Fehlerbehandlung selbst - eine
     * kurze Datenbank-Störung als `warning`, alles andere als `error` - liegt
     * in `OpportunisticWork`, gemeinsam mit Mailwarteschlange und Mail-Badge.
     */
    private function processIfDue(Request $request): void
    {
        OpportunisticWork::run(
            $this->logger,
            'Opportunistic reminder processing',
            $this->eventPrefix(),
            function () use ($request): void {
                if (!OpportunisticRunGate::tryClaim($this->markerKey(), static::CHECK_INTERVAL_SECONDS)) {
                    return;
                }

                $reminderService = ($this->reminderServiceFactory)();
                $reminderService->processDue(AppUrlResolver::resolveBaseUrl($request));
            }
        );
    }
}
