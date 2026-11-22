<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\AppSetting;
use App\Queries\UserQuery;
use App\Services\RememberLoginRestoreService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\LoginRedirect;
use App\Util\SessionConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AuthMiddleware implements MiddlewareInterface
{
    private UserQuery $userQuery;
    private RememberLoginService $rememberLoginService;
    private SessionAuthService $sessionAuthService;
    private RememberLoginRestoreService $rememberLoginRestoreService;
    private LoggerInterface $logger;

    /**
     * Der Wiederherstellungs-Dienst wird hier gebaut und nicht übergeben: Er
     * braucht genau die drei Mitarbeiter, die diese Middleware ohnehin schon
     * bekommt. Ein vierter Parameter zwänge jede der zahlreichen Aufrufstellen
     * in den Tests zu einem Argument, das sie sich aus denselben drei
     * zusammensetzen müsste.
     *
     * Der Logger ist optional und fällt auf den NullLogger zurück - dieselbe
     * Lösung wie in der CsrfMiddleware. Verdrahtet wird er nicht von Hand: Die
     * Middleware hängt als Klassenname an der Routengruppe (Routes.php) und
     * kommt aus dem Container, der den echten Logger an diesem Parameter
     * einsetzt. Die acht Testklassen, die sie selbst bauen, brauchen deshalb
     * kein weiteres Argument, und keine von ihnen protokolliert ins Leere.
     */
    public function __construct(
        UserQuery $userQuery,
        RememberLoginService $rememberLoginService,
        SessionAuthService $sessionAuthService,
        LoggerInterface $logger = new NullLogger()
    ) {
        $this->userQuery = $userQuery;
        $this->rememberLoginService = $rememberLoginService;
        $this->sessionAuthService = $sessionAuthService;
        $this->logger = $logger;
        $this->rememberLoginRestoreService = new RememberLoginRestoreService(
            $rememberLoginService,
            $userQuery,
            $sessionAuthService
        );
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        SessionConfig::ensureStarted();

        // Keine Pfad-Ausnahmen: Was diese Middleware umschließt, ist geschützt.
        //
        // Hier stand eine Liste, die `/login`, `/setup` und `/` durchwinkte. Diese
        // Routen liegen aber außerhalb der geschützten Gruppe, an der die Middleware
        // hängt (siehe Routes.php), der Block lief also nie. Harmlos war er nur
        // solange: Würde die Middleware jemals global registriert, wäre `/` still
        // ungeschützt - und das fiele niemandem auf, weil die Liste wie eine bewusste
        // Entscheidung aussah statt wie ein Überbleibsel.
        if (!isset($_SESSION['user_id'])) {
            $this->rememberLoginRestoreService->restoreFromCookie($request);
        }

        if (!isset($_SESSION['user_id'])) {
            return LoginRedirect::respond($request);
        }

        $sessionValidAfter = (int) (AppSetting::query()
            ->where('setting_key', 'session_valid_after')
            ->value('setting_value') ?? 0);
        $authEpoch = (int) ($_SESSION['auth_epoch'] ?? 0);

        if ($sessionValidAfter > 0 && $authEpoch < $sessionValidAfter) {
            return $this->endSession($request, 'revoked_globally');
        }

        $currentUser = $this->userQuery->findForSession((int) $_SESSION['user_id']);
        if (!$currentUser || !(bool) $currentUser->is_active) {
            $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
            if (is_string($rememberCookie) && $rememberCookie !== '') {
                $this->rememberLoginService->invalidateByCookieValue($rememberCookie);
                $this->rememberLoginService->clearRememberCookie();
            }

            // Ein Grund für beide Fälle, und das ist keine Bequemlichkeit: Ein
            // stillgelegtes Konto kommt hier gar nicht als Modell an, weil
            // `findForSession()` auf `is_active = 1` filtert. Die Prüfung darüber
            // bleibt als Netz für den Fall, dass der Filter dort einmal fällt -
            // aber "gelöscht" und "stillgelegt" auseinanderzuhalten, gäbe diese
            // Stelle nur vor. Ein Grund, der nie auftaucht, ist in der
            // Log-Analyse schlimmer als keiner: Sie läse daraus, dass eine
            // Stilllegung niemanden abmeldet.
            return $this->endSession($request, 'user_unavailable');
        }

        // Die Grenze des Kontos: gesetzt, wenn ein Passwortwechsel alle anderen
        // Anmeldungen beendet hat (AccountLoginRevocationService). Wer davor
        // angemeldet wurde, muss sich neu anmelden.
        $accountValidAfter = (int) ($currentUser->sessions_valid_after ?? 0);
        if ($accountValidAfter > 0 && $authEpoch < $accountValidAfter) {
            return $this->endSession($request, 'revoked_for_account');
        }

        // Der Rechte-Abzug der Sitzung wird bei jeder geschützten Anfrage neu
        // gesetzt: Eine Rollenänderung wirkt damit beim nächsten Aufruf, nicht
        // erst nach der nächsten Anmeldung.
        $this->sessionAuthService->setAuthenticatedUser($currentUser);

        return $handler->handle($request);
    }

    /**
     * Einziger Ausgang für alle erzwungenen Abmeldungen: beendet die Sitzung,
     * protokolliert sie genau einmal und schickt zur Anmeldung.
     *
     * Die drei Anlässe standen vorher als drei gleichlautende Zweige da -
     * `clearSession()` und `LoginRedirect::respond()` -, und keiner schrieb eine
     * Zeile. Protokolliert wurde nur das Setzen der Sperre
     * (`auth.sessions.invalidated`, `auth.account_logins.revoked`), nie ihr
     * Greifen. Für die tägliche Log-Analyse heißt das: "Ich werde dauernd
     * abgemeldet" war nicht nachvollziehbar, weil die Abmeldung selbst nirgends
     * auftaucht - und ob eine Sitzung an der globalen Sperre, an einem
     * stillgelegten Konto oder an der Kontogrenze nach einem Passwortwechsel
     * endete, unterscheidet nur dieser Grund.
     *
     * Eine Zeile je beendeter Sitzung, kein Rauschen: Nach `clearSession()` ist
     * die Sitzung zerstört, die nächste Anfrage kommt ohne Benutzerkennung an und
     * landet schon weiter oben bei der Weiterleitung.
     *
     * Benutzerkennung und IP kommen über den Request-Kontext und werden hier
     * nicht wiederholt - dieselbe Aufteilung wie bei `authz.denied` in der
     * RoleMiddleware.
     *
     * @param string $reason Einer von `revoked_globally`, `user_unavailable`,
     *        `revoked_for_account`.
     */
    private function endSession(Request $request, string $reason): Response
    {
        $this->sessionAuthService->clearSession();

        $this->logger->info('Session ended by the server.', [
            'event' => 'auth.session.ended',
            'reason' => $reason,
        ]);

        return LoginRedirect::respond($request);
    }
}
