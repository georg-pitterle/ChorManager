<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Models\User;
use App\Models\Role;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;

class AuthController
{
    private const ATTENDANCE_SELECTED_EVENT_SESSION_KEY = 'attendance_selected_event_id';

    private Twig $view;
    private UserQuery $userQuery;
    private RememberLoginService $rememberLoginService;
    private SessionAuthService $sessionAuthService;

    public function __construct(
        Twig $view,
        UserQuery $userQuery,
        RememberLoginService $rememberLoginService,
        SessionAuthService $sessionAuthService
    ) {
        $this->view = $view;
        $this->userQuery = $userQuery;
        $this->rememberLoginService = $rememberLoginService;
        $this->sessionAuthService = $sessionAuthService;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        // Redirect to setup if no users exist
        if (User::count() === 0) {
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($this->tryAutoLoginFromRememberCookie($request)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);

        return $this->view->render($response, 'auth/login.twig', [
            'error' => $error
        ]);
    }

    public function processLogin(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $remember = isset($data['remember']) && $data['remember'] === '1';

        $user = $this->userQuery->findByEmail($email);

        if ($user && password_verify($password, $user->password)) {
            session_regenerate_id(true);
            $this->sessionAuthService->setAuthenticatedUser($user);
            unset($_SESSION[self::ATTENDANCE_SELECTED_EVENT_SESSION_KEY]);

            if ($remember) {
                $tokenValue = $this->rememberLoginService->issueForUser((int) $user->id, $request);
                $this->rememberLoginService->setRememberCookie($tokenValue);
            } else {
                $existingRememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
                if (is_string($existingRememberCookie) && $existingRememberCookie !== '') {
                    $this->rememberLoginService->invalidateByCookieValue($existingRememberCookie);
                }
                $this->rememberLoginService->clearRememberCookie();
            }

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $_SESSION['error'] = 'Ungültige E-Mail-Adresse oder Passwort.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    public function showSetup(Request $request, Response $response): Response
    {
        // Only allow setup if no users exist
        if (User::count() > 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);

        return $this->view->render($response, 'auth/setup.twig', [
            'error' => $error
        ]);
    }

    public function processSetup(Request $request, Response $response): Response
    {
        // Protect against running setup if users already exist
        if (User::count() > 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$firstName || !$lastName || !$email || !$password) {
            $_SESSION['error'] = 'Alle Felder sind Pflichtfelder.';
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        try {
            // First create the Admin Role
            $adminRole = Role::firstOrCreate(['name' => 'Admin'], [
                'hierarchy_level' => 100,
                'can_manage_users' => 1,
                'can_edit_users' => 1,
                'can_manage_project_members' => 1,
                'can_manage_finances' => 1,
                'can_manage_master_data' => 1
            ]);

            // Create the first user
            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_active' => 1
            ]);

            // Assign Admin role
            $user->roles()->attach($adminRole->id);

            // Log them in immediately
            session_regenerate_id(true);
            $this->sessionAuthService->setAuthenticatedUser($user->load(['roles', 'voiceGroups']));

            $_SESSION['success'] = 'Administratorkonto erfolgreich erstellt!';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Erstellen des Kontos: ' . $e->getMessage();
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
        if (is_string($rememberCookie) && $rememberCookie !== '') {
            $this->rememberLoginService->invalidateByCookieValue($rememberCookie);
        }

        $this->rememberLoginService->clearRememberCookie();
        $this->sessionAuthService->clearSession();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function tryAutoLoginFromRememberCookie(Request $request): bool
    {
        $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
        if (!is_string($rememberCookie) || $rememberCookie === '') {
            return false;
        }

        $rememberToken = $this->rememberLoginService->validateCookieValue($rememberCookie);
        if (!$rememberToken) {
            $this->rememberLoginService->clearRememberCookie();
            return false;
        }

        $user = $this->userQuery->findById((int) $rememberToken->user_id);
        if (!$user || !(bool) $user->is_active) {
            $rememberToken->delete();
            $this->rememberLoginService->clearRememberCookie();
            return false;
        }

        session_regenerate_id(true);
        $this->sessionAuthService->setAuthenticatedUser($user);
        unset($_SESSION[self::ATTENDANCE_SELECTED_EVENT_SESSION_KEY]);

        $rotatedToken = $this->rememberLoginService->rotateToken($rememberToken, $request);
        $this->rememberLoginService->setRememberCookie($rotatedToken);

        return true;
    }
}
