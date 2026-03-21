<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Queries\UserQuery;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    private UserQuery $userQuery;
    private RememberLoginService $rememberLoginService;
    private SessionAuthService $sessionAuthService;

    public function __construct(
        UserQuery $userQuery,
        RememberLoginService $rememberLoginService,
        SessionAuthService $sessionAuthService
    ) {
        $this->userQuery = $userQuery;
        $this->rememberLoginService = $rememberLoginService;
        $this->sessionAuthService = $sessionAuthService;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Exclude the login and setup routes
        $path = $request->getUri()->getPath();
        if ($path === '/login' || $path === '/setup' || $path === '/') {
            return $handler->handle($request);
        }

        $this->rememberLoginService->clearExpiredTokens();

        if (!isset($_SESSION['user_id'])) {
            $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
            if (is_string($rememberCookie) && $rememberCookie !== '') {
                $rememberToken = $this->rememberLoginService->validateCookieValue($rememberCookie);

                if ($rememberToken) {
                    $user = $this->userQuery->findById((int) $rememberToken->user_id);
                    if ($user && (bool) $user->is_active) {
                        session_regenerate_id(true);
                        $this->sessionAuthService->setAuthenticatedUser($user);

                        $rotatedToken = $this->rememberLoginService->rotateToken($rememberToken, $request);
                        $this->rememberLoginService->setRememberCookie($rotatedToken);
                    } else {
                        $rememberToken->delete();
                        $this->rememberLoginService->clearRememberCookie();
                    }
                } else {
                    $this->rememberLoginService->clearRememberCookie();
                }
            }
        }

        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Pass session data to view globally, can be done via middleware or Twig extension.
        // For simplicity, we can inject globals into Twig in Dependencies.php, but since we use Slim-Twig,
        // we can also do it there.

        return $handler->handle($request);
    }
}
