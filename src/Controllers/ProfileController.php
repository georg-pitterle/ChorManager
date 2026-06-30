<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Models\VoiceGroup;
use App\Models\SubVoice;
use App\Services\MailCredentialCryptoService;
use App\Services\PasswordPolicyService;
use App\Util\BlockedHostException;
use App\Util\OutboundConnectionGuard;
use Psr\Log\LoggerInterface;

class ProfileController
{
    private const IMAP_ENCRYPTIONS = ['ssl', 'tls', 'none'];

    private Twig $view;
    private UserQuery $userQuery;
    private PasswordPolicyService $passwordPolicyService;
    private LoggerInterface $logger;
    private MailCredentialCryptoService $crypto;

    public function __construct(
        Twig $view,
        UserQuery $userQuery,
        PasswordPolicyService $passwordPolicyService,
        LoggerInterface $logger,
        MailCredentialCryptoService $crypto
    ) {
        $this->view = $view;
        $this->userQuery = $userQuery;
        $this->passwordPolicyService = $passwordPolicyService;
        $this->logger = $logger;
        $this->crypto = $crypto;
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $user = $this->userQuery->findById($userId);

        // Prepare voice group data for template
        $user->voice_group_ids = $user->voiceGroups->pluck('id')->toArray();
        $pivots = [];
        foreach ($user->voiceGroups as $vg) {
            $pivots[$vg->id] = $vg->pivot->sub_voice_id;
        }
        $user->voice_group_pivots = $pivots;

        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['success'], $_SESSION['error']);

        $voiceGroups = VoiceGroup::orderBy('id')->get();
        $subVoices = SubVoice::orderBy('id')->get();

        $mailAccount = $user->mailAccount;
        $formOld = $_SESSION['mailbox_form_old'] ?? null;
        unset($_SESSION['mailbox_form_old']);

        return $this->view->render($response, 'profile/index.twig', [
            'user' => $user,
            'success' => $success,
            'error' => $error,
            'voice_groups' => $voiceGroups,
            'sub_voices' => $subVoices,
            'mail_account' => $formOld !== null ? $this->mailboxViewFromOldInput($formOld) : $mailAccount,
            'has_saved_account' => $mailAccount !== null,
            'webmail_available' => $mailAccount !== null && (bool)$mailAccount->imap_enabled,
        ]);
    }

    /**
     * Rebuilds the mailbox form values from a flashed, failed submission so the
     * user does not have to retype everything after "Verbindung testen".
     * Deliberately omits imap_password - never echo a submitted password back.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mailboxViewFromOldInput(array $data): array
    {
        return [
            'imap_host' => trim((string)($data['imap_host'] ?? '')),
            'imap_port' => trim((string)($data['imap_port'] ?? '')),
            'imap_encryption' => trim((string)($data['imap_encryption'] ?? '')),
            'imap_username' => trim((string)($data['imap_username'] ?? '')),
            'imap_enabled' => $this->isCheckboxChecked($data, 'imap_enabled'),
            'mail_badge_enabled' => $this->isCheckboxChecked($data, 'mail_badge_enabled'),
            'smtp_host' => trim((string)($data['smtp_host'] ?? '')),
            'smtp_port' => trim((string)($data['smtp_port'] ?? '')),
            'smtp_encryption' => trim((string)($data['smtp_encryption'] ?? '')),
        ];
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
            $this->logger->error(
                'Profile update failed.',
                [
                    'event' => 'profile.update.failed',
                    'user_id' => $userId,
                    'exception' => $e,
                ]
            );
            $_SESSION['error'] = 'Fehler beim Speichern.';
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

        $passwordError = $this->passwordPolicyService->validate($newPassword);
        if ($passwordError !== null) {
            $_SESSION['error'] = $passwordError;
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

    public function updateMailbox(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $data = (array)$request->getParsedBody();

        $imapHost = trim((string)($data['imap_host'] ?? ''));
        $imapPortRaw = trim((string)($data['imap_port'] ?? ''));
        $imapEncryption = trim((string)($data['imap_encryption'] ?? ''));
        $imapUsername = trim((string)($data['imap_username'] ?? ''));
        $imapPassword = (string)($data['imap_password'] ?? '');
        $smtpHost = trim((string)($data['smtp_host'] ?? ''));
        $smtpPortRaw = trim((string)($data['smtp_port'] ?? ''));
        $smtpEncryption = trim((string)($data['smtp_encryption'] ?? ''));

        $error = $this->validateMailboxConnectionFields($imapHost, $imapPortRaw, $imapEncryption);
        if ($error === null && ($imapUsername === '' || strlen($imapUsername) > 255)) {
            $error = 'Bitte gib einen gültigen Benutzernamen an (max. 255 Zeichen).';
        }

        if ($error === null && self::containsControlChars($imapUsername)) {
            $error = 'Der Benutzername darf keine Steuerzeichen enthalten.';
        }

        if ($error === null && $imapPassword !== '' && self::containsControlChars($imapPassword)) {
            $error = 'Das Passwort darf keine Steuerzeichen enthalten.';
        }

        $existingAccount = UserMailAccount::where('user_id', $userId)->first();

        if ($error === null && $imapPassword === '' && !$existingAccount) {
            $error = 'Bitte gib ein Passwort für den Mailbox-Zugang an.';
        }

        if ($error !== null) {
            $_SESSION['error'] = $error;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $imapEnabled = $this->isCheckboxChecked($data, 'imap_enabled');
        $mailBadgeEnabled = $this->isCheckboxChecked($data, 'mail_badge_enabled');

        $smtpPort = ($smtpPortRaw !== '' && ctype_digit($smtpPortRaw)) ? (int)$smtpPortRaw : null;
        $validEncryptions = ['ssl', 'tls', 'none'];

        $attributes = [
            'imap_host' => $imapHost,
            'imap_port' => (int)$imapPortRaw,
            'imap_encryption' => $imapEncryption,
            'smtp_host' => $smtpHost !== '' ? $smtpHost : null,
            'smtp_port' => ($smtpHost !== '' && $smtpPort !== null && $smtpPort >= 1 && $smtpPort <= 65535)
                ? $smtpPort : null,
            'smtp_encryption' => ($smtpHost !== '' && in_array($smtpEncryption, $validEncryptions, true))
                ? $smtpEncryption : null,
            'imap_username' => $imapUsername,
            'imap_enabled' => $imapEnabled,
            'mail_badge_enabled' => $mailBadgeEnabled,
        ];

        if ($imapPassword !== '') {
            $attributes['imap_password_enc'] = $this->crypto->encrypt($imapPassword);
        }

        try {
            UserMailAccount::updateOrCreate(['user_id' => $userId], $attributes);

            $_SESSION['success'] = 'Mailbox-Einstellungen wurden gespeichert.';
        } catch (\Exception $e) {
            $this->logger->error(
                'Mail account update failed.',
                [
                    'event' => 'mail_account.update.failed',
                    'user_id' => $userId,
                    'exception' => $e,
                ]
            );
            $_SESSION['error'] = 'Fehler beim Speichern der Mailbox-Einstellungen.';
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    public function testMailboxConnection(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $_SESSION['mailbox_form_old'] = array_diff_key($data, ['imap_password' => true]);

        $imapHost = trim((string)($data['imap_host'] ?? ''));
        $imapPortRaw = trim((string)($data['imap_port'] ?? ''));
        $imapEncryption = trim((string)($data['imap_encryption'] ?? ''));

        $error = $this->validateMailboxConnectionFields($imapHost, $imapPortRaw, $imapEncryption);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        try {
            $validatedIp = OutboundConnectionGuard::resolvePublicIp($imapHost);
        } catch (BlockedHostException $e) {
            $this->logger->warning(
                'Mailbox connection test blocked: host did not resolve to a public address.',
                [
                    'event' => 'mailbox.test.host_blocked',
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                ]
            );
            $_SESSION['error'] = 'Verbindung fehlgeschlagen: Host ist nicht erreichbar.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $imapPort = (int)$imapPortRaw;
        $scheme = $imapEncryption === 'ssl' ? 'ssl' : 'tcp';
        // Connect to the validated IP (pinned), but keep TLS peer verification
        // bound to the original hostname so a rebind cannot redirect us.
        $ipForUrl = str_contains($validatedIp, ':') ? '[' . $validatedIp . ']' : $validatedIp;
        $remote = $scheme . '://' . $ipForUrl . ':' . $imapPort;

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $imapHost,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            // Deliberately generic: do not echo $errstr, which would leak an
            // open/closed/filtered oracle for the targeted host:port.
            $_SESSION['error'] = 'Verbindung fehlgeschlagen: Host ist nicht erreichbar.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        stream_set_timeout($socket, 5);
        $greeting = fgets($socket, 512);
        fclose($socket);

        if ($greeting !== false && str_starts_with($greeting, '* ')) {
            $_SESSION['success'] = 'Verbindung erfolgreich.';
        } else {
            $_SESSION['error'] = 'Verbindung fehlgeschlagen: keine gültige IMAP-Antwort erhalten.';
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    private function validateMailboxConnectionFields(
        string $imapHost,
        string $imapPortRaw,
        string $imapEncryption
    ): ?string {
        if ($imapHost === '' || strlen($imapHost) > 255 || preg_match('/\s/', $imapHost)) {
            return 'Bitte gib einen gültigen Host ohne Leerzeichen an (max. 255 Zeichen).';
        }

        if ($imapPortRaw === '' || !ctype_digit($imapPortRaw)) {
            return 'Bitte gib einen gültigen Port an.';
        }

        $imapPort = (int)$imapPortRaw;
        if ($imapPort < 1 || $imapPort > 65535) {
            return 'Der Port muss zwischen 1 und 65535 liegen.';
        }

        if (!in_array($imapEncryption, self::IMAP_ENCRYPTIONS, true)) {
            return 'Bitte wähle eine gültige Verschlüsselung (SSL, TLS oder Keine).';
        }

        return null;
    }

    /**
     * Reject ASCII control characters (incl. CR/LF/NUL). Credentials carrying
     * CR/LF would break out of an IMAP quoted-string and inject commands; they
     * are never valid in a username or password anyway.
     */
    private static function containsControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function isCheckboxChecked(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data)) {
            return false;
        }

        $value = $data[$key];

        return $value === '1' || $value === 'on' || $value === true || $value === 1;
    }
}
