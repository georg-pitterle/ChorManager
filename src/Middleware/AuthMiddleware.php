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

class AuthMiddleware implements MiddlewareInterface
{
    private UserQuery $userQuery;
    private RememberLoginService $rememberLoginService;
    private SessionAuthService $sessionAuthService;
    private RememberLoginRestoreService $rememberLoginRestoreService;

    /**
     * Der Wiederherstellungs-Dienst wird hier gebaut und nicht übergeben: Er
     * braucht genau die drei Mitarbeiter, die diese Middleware ohnehin schon
     * bekommt. Ein vierter Parameter zwänge jede der zahlreichen Aufrufstellen
     * in den Tests zu einem Argument, das sie sich aus denselben drei
     * zusammensetzen müsste.
     */
    public function __construct(
        UserQuery $userQuery,
        RememberLoginService $rememberLoginService,
        SessionAuthService $sessionAuthService
    ) {
        $this->userQuery = $userQuery;
        $this->rememberLoginService = $rememberLoginService;
        $this->sessionAuthService = $sessionAuthService;
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
            $this->sessionAuthService->clearSession();

            return LoginRedirect::respond($request);
        }

        $currentUser = $this->userQuery->findForSession((int) $_SESSION['user_id']);
        if (!$currentUser || !(bool) $currentUser->is_active) {
            $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
            if (is_string($rememberCookie) && $rememberCookie !== '') {
                $this->rememberLoginService->invalidateByCookieValue($rememberCookie);
                $this->rememberLoginService->clearRememberCookie();
            }

            $this->sessionAuthService->clearSession();

            return LoginRedirect::respond($request);
        }

        // Refresh rights snapshot on every protected request.
        $this->sessionAuthService->setAuthenticatedUser($currentUser);

        // Pass session data to view globally, can be done via middleware or Twig extension.
        // For simplicity, we can inject globals into Twig in Dependencies.php, but since we use Slim-Twig,
        // we can also do it there.

        return $handler->handle($request);
    }
}
