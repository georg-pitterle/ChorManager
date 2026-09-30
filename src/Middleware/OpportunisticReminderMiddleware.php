<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\AppUrlResolver;
use App\Util\OpportunisticRunGate;
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
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Der Schlüssel in `app_settings`, unter dem der Zeitpunkt des letzten Laufs
     * steht. Er trennt die beiden Erinnerungen voneinander - mit einem
     * gemeinsamen Merker bremste die eine die andere aus.
     */
    abstract protected function markerKey(): string;

    /**
     * Der `event`-Schlüssel, unter dem ein Fehlschlag ins Protokoll geht.
     */
    abstract protected function failureEvent(): string;

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->processIfDue($request);

        return $handler->handle($request);
    }

    private function processIfDue(Request $request): void
    {
        try {
            if (!OpportunisticRunGate::tryClaim($this->markerKey(), static::CHECK_INTERVAL_SECONDS)) {
                return;
            }

            $reminderService = ($this->reminderServiceFactory)();
            $reminderService->processDue(AppUrlResolver::resolveBaseUrl($request));
        } catch (\Throwable $exception) {
            // Die Meldung ist für beide dieselbe; unterschieden wird über den
            // `event`-Schlüssel, und der ist laut Logging-Standard ohnehin der
            // stabile Bezeichner einer Logzeile.
            $this->logger->error('Opportunistic reminder processing failed.', [
                'event' => $this->failureEvent(),
                'exception' => $exception,
            ]);
        }
    }
}
