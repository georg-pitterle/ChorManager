<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Models\User;

class ProfileController
{
    private Twig $view;
    private UserQuery $userQuery;

    public function __construct(Twig $view, UserQuery $userQuery)
    {
        $this->view = $view;
        $this->userQuery = $userQuery;
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $user = $this->userQuery->findById($userId);

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        return $this->view->render($response, 'profile/index.twig', [
            'user' => $user,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $data = (array)$request->getParsedBody();

        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');

        if (!$firstName || !$lastName || !$email) {
            $_SESSION['error'] = 'Bitte fülle alle Pflichtfelder aus.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        // Check email uniqueness excluding self
        if (User::where('email', $email)->where('id', '!=', $userId)->exists()) {
            $_SESSION['error'] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        try {
            $user = User::find($userId);
            if ($user) {
                $user->first_name = $firstName;
                $user->last_name = $lastName;
                $user->email = $email;
                $user->save();

                $_SESSION['success'] = 'Dein Profil wurde erfolgreich aktualisiert.';
                $_SESSION['user_name'] = $firstName;
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Fehler beim Speichern: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    public function updatePassword(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $data = (array)$request->getParsedBody();

        $oldPassword = $data['old_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $newPasswordConfirm = $data['new_password_confirm'] ?? '';

        if (!$oldPassword || !$newPassword || !$newPasswordConfirm) {
            $_SESSION['error'] = 'Bitte füllen Sie alle Felder aus.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        if ($newPassword !== $newPasswordConfirm) {
            $_SESSION['error'] = 'Das neue Passwort und die Bestätigung stimmen nicht überein.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $user = User::find($userId);

        if (!$user || !password_verify($oldPassword, $user->password)) {
            $_SESSION['error'] = 'Das bisherige Passwort ist falsch.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        $_SESSION['success'] = 'Dein Passwort wurde erfolgreich aktualisiert.';

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }
}
