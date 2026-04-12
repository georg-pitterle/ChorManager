<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\User;
use App\Models\PasswordReset;
use App\Services\Mailer;
use App\Util\EnvHelper;

class PasswordResetController
{
    private Twig $view;
    private Mailer $mailer;

    public function __construct(Twig $view, ?Mailer $mailer = null)
    {
        $this->view = $view;
        $this->mailer = $mailer ?? new Mailer();
    }

    public function showForgotForm(Request $request, Response $response): Response
    {
        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);

        return $this->view->render($response, 'auth/forgot_password.twig', [
            'error' => $error,
            'success' => $success
        ]);
    }

    public function sendResetLink(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = strtolower(trim($data['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
            return $response->withHeader('Location', '/forgot-password')->withStatus(302);
        }

        $user = User::where('email', $email)->first();

        if (!$user || !$user->is_active) {
            // we show success anyway to prevent email enumeration
            $_SESSION['success'] = 'Existiert die E-Mail-Adresse, wurde ein Link zum Zurücksetzen des Passworts gesendet.';
            return $response->withHeader('Location', '/forgot-password')->withStatus(302);
        }

        // Generate token and save to db
        // We use bin2hex(random_bytes) to avoid dependency on Str::random if Illuminate/Support isn't fully loaded,
        // but Laravel's helper is available because we use illuminate/database
        $token = bin2hex(random_bytes(32));

        // delete old tokens
        PasswordReset::where('email', $email)->delete();

        PasswordReset::create([
            'email' => $email,
            'token' => password_hash($token, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $resetLink = $this->buildTrustedAppUrl() . '/reset-password?token=' . $token . '&email=' . urlencode($email);

        $htmlBody = $this->view->fetch('emails/password_reset.twig', [
            'user' => $user,
            'reset_link' => $resetLink
        ]);

        $sent = $this->mailer->sendHtmlMail($email, 'Passwort zurücksetzen - Chor-Manager', $htmlBody);

        if ($sent) {
            $_SESSION['success'] = 'Existiert die E-Mail-Adresse, wurde ein Link zum Zurücksetzen des Passworts gesendet.';
        } else {
            $_SESSION['error'] = 'Fehler beim Senden der E-Mail. Bitte kontaktiere den Administrator.';
        }

        return $response->withHeader('Location', '/forgot-password')->withStatus(302);
    }

    private function buildTrustedAppUrl(): string
    {
        $configured = trim((string) EnvHelper::read('APP_URL', 'http://localhost'));

        $parts = parse_url($configured);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if (($scheme === 'http' || $scheme === 'https') && is_string($host) && $host !== '') {
            return rtrim($configured, '/');
        }

        return 'http://localhost';
    }

    public function showResetForm(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['token'] ?? '';
        $email = $queryParams['email'] ?? '';

        if (!$token || !$email) {
            $_SESSION['error'] = 'Ungültiger oder fehlender Token.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);

        return $this->view->render($response, 'auth/reset_password.twig', [
            'token' => $token,
            'email' => $email,
            'error' => $error
        ]);
    }

    public function processReset(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $token = $data['token'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';

        if (!$token || !$email || !$password) {
            $_SESSION['error'] = 'Bitte fülle alle Pflichtfelder aus.';
            $url = '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'Die Passwörter stimmen nicht überein.';
            $url = '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        if (strlen($password) < 6) {
            $_SESSION['error'] = 'Das Passwort muss mindestens 6 Zeichen lang sein.';
            $url = '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        $resetRecord = PasswordReset::where('email', $email)->first();

        if (!$resetRecord || !password_verify($token, $resetRecord->token)) {
            $_SESSION['error'] = 'Dieser Passwort-Reset-Link ist ungültig oder abgelaufen.';
            return $response->withHeader('Location', '/forgot-password')->withStatus(302);
        }

        // Check if token is older than 2 hours
        $createdAt = strtotime($resetRecord->created_at);
        if (time() - $createdAt > 7200) {
            PasswordReset::where('email', $email)->delete();
            $_SESSION['error'] = 'Dieser Passwort-Reset-Link ist abgelaufen. Bitte fordere einen neuen an.';
            return $response->withHeader('Location', '/forgot-password')->withStatus(302);
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->save();
            PasswordReset::where('email', $email)->delete();
            $_SESSION['success'] = 'Dein Passwort wurde erfolgreich geändert. Du kannst dich nun anmelden.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $_SESSION['error'] = 'Benutzer nicht gefunden.';
        $url = '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
