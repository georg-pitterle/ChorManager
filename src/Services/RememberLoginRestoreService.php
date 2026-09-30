<?php

declare(strict_types=1);

namespace App\Services;

use App\Queries\UserQuery;
use App\Util\Csrf;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stellt aus einem Remember-Me-Cookie eine angemeldete Sitzung her.
 *
 * Derselbe Ablauf stand zweimal im Code: in `AuthMiddleware::process()` für
 * jede geschützte Route und in `AuthController::tryAutoLoginFromRememberCookie()`
 * für die Anmeldeseite. Beide lasen das Cookie, prüften den Token, luden das
 * Mitglied, tauschten die Sitzungskennung, setzten die Sitzung und drehten den
 * Token weiter - mit kleinen Unterschieden, die niemand beabsichtigt hatte.
 * Zwei Abschriften desselben Anmeldewegs heißen zwei Stellen, an denen eine
 * Korrektur vergessen werden kann; hier steht er einmal.
 *
 * Bewusst ein eigener Dienst und keine weitere Methode am
 * `RememberLoginService`: Der wird an zahlreichen Stellen mit `new
 * RememberLoginService()` gebaut und kennt nur Token und Cookie. Ihm
 * `UserQuery` und `SessionAuthService` zu geben, machte aus einem Token-Dienst
 * einen, der Sitzungen anlegt, und zwänge jede dieser Stellen zu zwei weiteren
 * Argumenten.
 */
class RememberLoginRestoreService
{
    public function __construct(
        private readonly RememberLoginService $rememberLoginService,
        private readonly UserQuery $userQuery,
        private readonly SessionAuthService $sessionAuthService
    ) {
    }

    /**
     * Meldet, ob aus dem Cookie eine Sitzung geworden ist.
     *
     * Ohne Cookie passiert nichts - insbesondere wird nicht aufgeräumt. Die
     * Löschabfrage lief zuvor bei jedem nicht angemeldeten Aufruf einer
     * geschützten Route, also auch für jeden Suchroboter, der nie ein Token
     * besessen hat. Liegen bleibt dadurch nichts von Belang: Ein abgelaufenes
     * Token weist `validateCookieValue()` ohnehin ab, und wer eines besitzt,
     * räumt beim nächsten eigenen Besuch mit auf.
     */
    public function restoreFromCookie(Request $request): bool
    {
        $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
        if (!is_string($rememberCookie) || $rememberCookie === '') {
            return false;
        }

        $this->rememberLoginService->clearExpiredTokens();

        $rememberToken = $this->rememberLoginService->validateCookieValue($rememberCookie);
        if ($rememberToken === null) {
            $this->rememberLoginService->clearRememberCookie();

            return false;
        }

        $user = $this->userQuery->findForSession((int) $rememberToken->user_id);
        if (!$user || !(bool) $user->is_active) {
            $rememberToken->delete();
            $this->rememberLoginService->clearRememberCookie();

            return false;
        }

        session_regenerate_id(true);
        Csrf::rotate();
        $this->sessionAuthService->setAuthenticatedUser($user);

        // Das Token gilt genau einmal: Wer es vorzeigt, bekommt ein neues, und
        // das alte ist danach wertlos. Ein abgegriffenes Cookie taugt damit nur
        // bis zum nächsten eigenen Besuch der bestohlenen Person.
        $rotatedToken = $this->rememberLoginService->rotateToken($rememberToken, $request);
        $this->rememberLoginService->setRememberCookie($rotatedToken);

        return true;
    }
}
