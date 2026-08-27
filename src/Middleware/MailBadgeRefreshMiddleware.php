<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Controllers\MailBadgeController;
use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use Carbon\Carbon;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

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
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->refreshIfDue($request);

        return $handler->handle($request);
    }

    private function refreshIfDue(Request $request): void
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

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

            $this->refreshWithBackOff($account);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Mail badge opportunistic refresh failed.',
                [
                    'event' => 'mail_badge.middleware.failed',
                    'exception' => $exception,
                ]
            );
        }
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
