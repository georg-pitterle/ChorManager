<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Controllers\MailBadgeController;
use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use App\Util\AfterResponse;
use App\Util\OpportunisticWork;
use App\Util\SessionConfig;
use Carbon\Carbon;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class MailBadgeRefreshMiddleware implements MiddlewareInterface
{
    private const STALENESS_MINUTES = 5;

    /**
     * Untergrenze für erzwungene Abgleiche.
     *
     * Ein erzwungener Abgleich überspringt die reguläre Wartezeit, aber nicht jede
     * Bremse: Die Fokus-Abfrage des Frontends feuert bei jedem Wechsel zurück in den
     * Tab, und ohne Untergrenze entstünde beim schnellen Hin- und Herschalten für
     * jeden einzelnen Wechsel eine eigene IMAP-Verbindung. Deutlich kürzer als die
     * reguläre Wartezeit, weil hier ein konkreter Anlass vorliegt - jemand hat gerade
     * sein Postfach angesehen.
     */
    private const FORCED_STALENESS_SECONDS = 15;

    /**
     * Name und Ereignis-Stamm der nebenbei erledigten Arbeit (OpportunisticWork).
     * Der `event`-Schlüssel ist laut instructions/logging.md der stabile
     * Bezeichner einer Logzeile und bleibt deshalb der, der hier immer stand.
     */
    private const WORK_LABEL = 'Mail badge opportunistic refresh';
    private const EVENT_PREFIX = 'mail_badge.middleware';

    /**
     * The badge service is resolved through a factory (rather than injected
     * directly) so that constructing it - which loads MAIL_CREDENTIAL_KEY and
     * fails closed on a missing/invalid key - happens lazily inside the guarded
     * refresh path. A mail-subsystem misconfiguration must degrade the badge
     * only, never 500 every page on this global middleware.
     *
     * @param Closure(): MailBadgeService $badgeServiceFactory
     */
    public function __construct(
        private readonly Closure $badgeServiceFactory,
        private readonly LoggerInterface $logger,
        private readonly AfterResponse $afterResponse
    ) {
    }

    /**
     * Zwei Teile, und die Trennung ist der ganze Punkt.
     *
     * Was die Sitzung liest und schreibt - die Benutzerkennung, der
     * Einmalvermerk aus dem Webmail -, läuft vor der Antwort. Danach gibt
     * `AfterResponse` die Sitzung frei und schließt sie; ein
     * `unset($_SESSION[...])` von dort erreicht die Platte nicht mehr. Dazu
     * gehört die Abfrage des Kontos: Sie ist eine Zeile aus der eigenen
     * Datenbank und lief auch vorher hier.
     *
     * Der IMAP-Abgleich läuft erst, wenn die Antwort beim Browser ist. Vorher
     * stand er vor dem Controller, und alle fünf Minuten wartete genau die
     * Person, deren Aufruf ihn auslöste, zusätzlich auf DNS, TLS, LOGIN und
     * STATUS - bei einem nicht erreichbaren Server bis zur
     * Verbindungszeitüberschreitung von fünf Sekunden. Für die Seite, die
     * gerade gebaut wird, braucht den Zählerstand niemand: Die
     * Navigationsleiste zeigt ihn aus den zwischengespeicherten Spalten, und
     * die Oberfläche fragt ihn beim Zurückwechseln in den Tab selbst nach.
     * Dieselbe Entscheidung wie bei Mailwarteschlange und Erinnerungen.
     *
     * Die eine Ausnahme ist der Badge-Endpunkt selbst: Seine Antwort trägt den
     * Zählerstand, also muss der Abgleich davor stehen.
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $account = $this->dueAccount($request);

        if ($account !== null && $this->isBadgeEndpoint($request)) {
            $this->refresh($account);

            return $handler->handle($request);
        }

        $response = $handler->handle($request);

        if ($account !== null) {
            $this->afterResponse->defer(fn () => $this->refresh($account));
        }

        return $response;
    }

    /**
     * Das Konto, das jetzt abzugleichen ist - oder null.
     *
     * @return UserMailAccount|null
     */
    private function dueAccount(Request $request): ?UserMailAccount
    {
        $due = null;

        OpportunisticWork::run($this->logger, self::WORK_LABEL, self::EVENT_PREFIX, function () use (
            $request,
            &$due
        ): void {
            SessionConfig::ensureStartedForKnownSession();

            $forced = $this->isForced($request);

            if (!isset($_SESSION['user_id'])) {
                $this->clearForceSignal();
                return;
            }

            $account = UserMailAccount::where('user_id', (int) $_SESSION['user_id'])->first();
            if ($account === null || !$account->imap_enabled || !$account->mail_badge_enabled) {
                // Es gibt nichts abzugleichen. Der Vermerk muss trotzdem weg, sonst
                // liegt er den Rest der Sitzung nutzlos herum.
                $this->clearForceSignal();
                return;
            }

            if (!$this->isDue($account, $forced)) {
                // Der Vermerk bleibt hier bewusst stehen: Gebremst hat nur die
                // Untergrenze. Räumte man ihn schon jetzt ab, könnte ihn eine beliebige
                // Zwischenanfrage - ein Symbol, der Service Worker - wirkungslos
                // aufbrauchen, und der Zähler bliebe bis zum Ablauf der regulären
                // Wartezeit auf dem Stand von vor dem Lesen stehen.
                return;
            }

            $this->clearForceSignal();

            $due = $account;
        });

        return $due;
    }

    /**
     * Der Abgleich selbst. Die Fehlerbehandlung - eine kurze Datenbank-Störung
     * als `warning`, alles andere als `error` - liegt in `OpportunisticWork`,
     * gemeinsam mit Mailwarteschlange und Erinnerungen. Hier muss sie stehen,
     * weil dieser Teil nach der Antwort läuft: Dort gibt es keine Fehlerseite
     * mehr, und eine Ausnahme ließe die übrigen Aufgaben derselben Anfrage aus.
     */
    private function refresh(UserMailAccount $account): void
    {
        OpportunisticWork::run(
            $this->logger,
            self::WORK_LABEL,
            self::EVENT_PREFIX,
            fn () => $this->refreshWithBackOff($account)
        );
    }

    /**
     * Ist die Wartezeit seit dem letzten Abgleich abgelaufen?
     *
     * Ein Konto, das noch nie abgeglichen wurde, ist immer fällig.
     */
    private function isDue(UserMailAccount $account, bool $forced): bool
    {
        if ($account->mail_last_checked_at === null) {
            return true;
        }

        $lastChecked = Carbon::parse($account->mail_last_checked_at);

        $nextDueAt = $forced
            ? $lastChecked->copy()->addSeconds(self::FORCED_STALENESS_SECONDS)
            : $lastChecked->copy()->addMinutes(self::STALENESS_MINUTES);

        return !$nextDueAt->isFuture();
    }

    /**
     * Verlangt dieser Request einen Abgleich ohne Rücksicht auf die reguläre Wartezeit?
     *
     * Zwei Anlässe zählen: der Einmalvermerk aus der Session, den der Start des
     * Webmails setzt, und der Aufruf des Badge-Endpunkts selbst - den fragt das
     * Frontend beim Zurückwechseln in den Tab ab, und zwar genau deshalb, weil der
     * angezeigte Zähler dann überholt sein könnte.
     *
     * Liest nur; abgeräumt wird der Vermerk erst, wenn ein Abgleich tatsächlich
     * stattfindet oder feststeht, dass es nichts abzugleichen gibt.
     */
    private function isForced(Request $request): bool
    {
        if (!empty($_SESSION[MailBadgeController::FORCE_SESSION_KEY])) {
            return true;
        }

        return $this->isBadgeEndpoint($request);
    }

    private function isBadgeEndpoint(Request $request): bool
    {
        return $request->getUri()->getPath() === MailBadgeController::REFRESH_PATH;
    }

    private function clearForceSignal(): void
    {
        unset($_SESSION[MailBadgeController::FORCE_SESSION_KEY]);
    }

    /**
     * Startet die Wartezeit auch dann, wenn der Abgleich scheitert.
     *
     * `MailBadgeService::refresh()` lässt die zwischengespeicherten Spalten bei einem
     * Fehlschlag bewusst unberührt - `mail_last_checked_at` eingeschlossen. Damit blieb
     * der Zeitstempel eines Kontos mit unerreichbarem oder falsch konfiguriertem
     * IMAP-Server für immer veraltet: Die Prüfung oben griff nie, und jeder einzelne
     * Seitenaufruf dieses Mitglieds baute erneut eine Verbindung auf, bis zur
     * Verbindungszeitüberschreitung. Der Vermerk hier ist reiner Wartezeit-Marker und
     * wird nirgends angezeigt; der zuletzt bekannte Zählerstand bleibt erhalten.
     */
    private function refreshWithBackOff(UserMailAccount $account): void
    {
        $refreshed = false;

        try {
            $refreshed = ($this->badgeServiceFactory)()->refresh($account);
        } finally {
            if (!$refreshed) {
                $account->mail_last_checked_at = Carbon::now();
                $account->save();
            }
        }
    }
}
