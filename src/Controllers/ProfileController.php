<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Queries\UserQuery;
use App\Logging\ExceptionLogContext;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Models\VoiceGroup;
use App\Models\SubVoice;
use App\Services\MailCredentialCryptoService;
use App\Services\NotificationService;
use App\Services\PasswordPolicyService;
use App\Services\RememberLoginService;
use App\Util\BlockedHostException;
use App\Util\NotificationType;
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
    private RememberLoginService $rememberLoginService;

    /**
     * Optional und am Ende, wie in den uebrigen Controllern: Bestehende Tests
     * bauen diesen Controller mit festen Positionsargumenten. Im Betrieb reicht
     * ihn die ausdrueckliche Registrierung in `Dependencies.php` durch.
     */
    private ?NotificationService $notificationService;

    public function __construct(
        Twig $view,
        UserQuery $userQuery,
        PasswordPolicyService $passwordPolicyService,
        LoggerInterface $logger,
        MailCredentialCryptoService $crypto,
        ?RememberLoginService $rememberLoginService = null,
        ?NotificationService $notificationService = null
    ) {
        $this->view = $view;
        $this->userQuery = $userQuery;
        $this->passwordPolicyService = $passwordPolicyService;
        $this->logger = $logger;
        $this->crypto = $crypto;
        $this->rememberLoginService = $rememberLoginService ?? new RememberLoginService();
        $this->notificationService = $notificationService;
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
        $subVoices = SubVoice::orderBy('name')->get();

        // Projects the user participates in, newest first (undated last).
        $projects = $user->projects()
            ->orderByRaw('start_date IS NULL, start_date DESC')
            ->get();

        $mailAccount = $user->mailAccount;
        $formOld = $_SESSION['mailbox_form_old'] ?? null;
        unset($_SESSION['mailbox_form_old']);

        // Angezeigt werden nur die Anlaesse, deren Modul laeuft und die die
        // Verwaltung nicht abgeschaltet hat - ein Haekchen fuer etwas, das ohnehin
        // nie kommt, waere ein Versprechen, das die Anwendung nicht haelt.
        $notificationGroups = [];
        $notificationSettings = [];
        if ($this->notificationService !== null) {
            $notificationGroups = $this->notificationService->availableGrouped();
            $notificationSettings = $this->notificationService->settingsFor($userId);
        }

        return $this->view->render($response, 'profile/index.twig', [
            'user' => $user,
            'notification_groups' => $notificationGroups,
            'notification_group_labels' => NotificationType::GROUPS,
            'notification_settings' => $notificationSettings,
            'success' => $success,
            'error' => $error,
            'voice_groups' => $voiceGroups,
            'sub_voices' => $subVoices,
            'projects' => $projects,
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
            'external_webmail_url' => trim((string)($data['external_webmail_url'] ?? '')),
        ];
    }

    /**
     * True when the client explicitly asked for a JSON response (the async
     * mailbox form's fetch() calls always send this). Non-JS/legacy form
     * submissions never send it, so they keep using the redirect+session-flash
     * path unchanged.
     */
    private function wantsJsonResponse(Request $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
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

        // Reject malformed or over-long addresses before the DB write. The email
        // column is varchar(255); the RFC caps a valid address at 254 octets.
        // Without this guard an over-long value hits the column limit and Eloquent
        // throws a QueryException, surfacing as a generic 500 instead of a
        // form-level hint.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $_SESSION['error'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
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

    /**
     * Ob und wie die eigenen Aufgaben im abonnierten Kalender ankommen.
     *
     * Eigene Route statt eines weiteren Felds im Profilformular: Das Formular
     * oben verlangt Vorname, Nachname und E-Mail und weist unvollständige
     * Eingaben ab - eine Kalendereinstellung dort mitzuführen hieße, sie bei
     * jeder abgewiesenen Namensänderung mit zu verlieren.
     */
    public function updateCalendarSettings(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $data = (array)$request->getParsedBody();

        $feed = (string)($data['calendar_task_feed'] ?? '');
        $format = (string)($data['calendar_task_format'] ?? '');

        if (!in_array($feed, User::CALENDAR_TASK_FEEDS, true)) {
            $_SESSION['error'] = 'Ungültige Auswahl für die Aufgaben im Kalender.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        if (!in_array($format, User::CALENDAR_TASK_FORMATS, true)) {
            $_SESSION['error'] = 'Ungültige Auswahl für die Darstellung der Aufgaben.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        try {
            $user = User::find($userId);
            if ($user) {
                $user->calendar_task_feed = $feed;
                $user->calendar_task_format = $format;
                $user->save();

                $_SESSION['success'] = 'Deine Kalendereinstellungen wurden gespeichert.';
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Calendar settings update failed.',
                [
                    'event' => 'profile.calendar.update.failed',
                    'user_id' => $userId,
                    'exception' => $e,
                ]
            );
            $_SESSION['error'] = 'Fehler beim Speichern.';
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    /**
     * Uebernimmt die Haekchen aus dem Reiter "Benachrichtigungen".
     *
     * Ausgewertet werden nur die Anlaesse, die das Formular ueberhaupt anbieten
     * durfte - sonst schaltete ein zusammengebauter Aufruf etwas ab, das die
     * Person gar nicht zu sehen bekam. Ein fehlender Schluessel heisst "Haekchen
     * raus": Nicht angehakte Kaestchen sendet ein Browser nicht mit.
     */
    public function updateNotificationSettings(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];

        if ($this->notificationService === null) {
            $_SESSION['error'] = 'Benachrichtigungen sind in dieser Installation nicht verfuegbar.';
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $submitted = (array)($data['notifications'] ?? []);

        $decisions = [];
        foreach ($this->notificationService->availableTypes() as $type) {
            $decisions[$type] = !empty($submitted[$type]);
        }

        try {
            $this->notificationService->storeSettings($userId, $decisions);
            $_SESSION['success'] = 'Deine Benachrichtigungen wurden gespeichert.';
        } catch (\Exception $e) {
            $this->logger->error(
                'Notification settings update failed.',
                [
                    'event' => 'profile.notifications.update.failed',
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

        // Von außen ist nicht erkennbar, ob der Wechsel freiwillig oder ein Ernstfall
        // ist. Deshalb fliegen alle Angemeldet-bleiben-Token des Kontos hinaus - auch
        // die der Geräte, deren Cookie hier gerade nicht vorliegt.
        $revoked = $this->rememberLoginService->invalidateAllForUser($userId);
        if ($revoked > 0) {
            $this->logger->info('Remember-me tokens revoked after password change.', [
                'event' => 'auth.remember_me.revoked',
                'reason' => 'password_changed',
                'user_id' => (int) $user->id,
                'revoked_count' => $revoked,
            ]);
        }

        // Die eigene Sitzung bleibt bestehen, bekommt aber eine neue Kennung: Wer sie
        // mitgelesen hat, kommt mit der alten nicht weiter.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $this->logger->info('Password changed.', [
            'event' => 'auth.password.changed',
            'user_id' => (int) $user->id,
        ]);

        $_SESSION['success'] = 'Dein Passwort wurde erfolgreich aktualisiert.';

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    public function updateMailbox(Request $request, Response $response): Response
    {
        $wantsJson = $this->wantsJsonResponse($request);
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
        $hasExternalWebmailUrl = array_key_exists('external_webmail_url', $data);
        $externalWebmailUrl = $hasExternalWebmailUrl ? trim((string)$data['external_webmail_url']) : '';

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

        if ($error === null && $externalWebmailUrl !== '') {
            $error = self::validateExternalWebmailUrl($externalWebmailUrl);
        }

        $existingAccount = UserMailAccount::where('user_id', $userId)->first();

        if ($error === null && $imapPassword === '' && !$existingAccount) {
            $error = 'Bitte gib ein Passwort für den Mailbox-Zugang an.';
        }

        if ($error !== null) {
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $error], 422);
            }
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

        if ($hasExternalWebmailUrl) {
            $attributes['external_webmail_url'] = $externalWebmailUrl !== '' ? $externalWebmailUrl : null;
        }

        if ($imapPassword !== '') {
            $attributes['imap_password_enc'] = $this->crypto->encrypt($imapPassword);
        }

        try {
            UserMailAccount::updateOrCreate(['user_id' => $userId], $attributes);

            if ($imapPassword !== '') {
                $this->logger->info('Mail credentials changed.', [
                    'event' => 'mail.credentials.changed',
                ]);
            }

            $message = 'Mailbox-Einstellungen wurden gespeichert.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => true, 'message' => $message], 200);
            }
            $_SESSION['success'] = $message;
        } catch (\Exception $e) {
            $this->logger->error(
                'Mail account update failed.',
                [
                    'event' => 'mail_account.update.failed',
                    'user_id' => $userId,
                ] + ExceptionLogContext::build($e)
            );
            $message = 'Fehler beim Speichern der Mailbox-Einstellungen.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 500);
            }
            $_SESSION['error'] = $message;
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    public function deleteMailbox(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];

        $account = UserMailAccount::where('user_id', $userId)->first();
        if ($account) {
            $account->delete();
            $_SESSION['success'] = 'Mailbox-Zugang wurde entfernt.';
        }

        return $response->withHeader('Location', '/profile')->withStatus(302);
    }

    public function testMailboxConnection(Request $request, Response $response): Response
    {
        $wantsJson = $this->wantsJsonResponse($request);
        $data = (array)$request->getParsedBody();
        if (!$wantsJson) {
            $_SESSION['mailbox_form_old'] = array_diff_key($data, ['imap_password' => true]);
        }

        $imapHost = trim((string)($data['imap_host'] ?? ''));
        $imapPortRaw = trim((string)($data['imap_port'] ?? ''));
        $imapEncryption = trim((string)($data['imap_encryption'] ?? ''));

        $error = $this->validateMailboxConnectionFields($imapHost, $imapPortRaw, $imapEncryption);
        if ($error !== null) {
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $error], 422);
            }
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
            $message = 'Verbindung fehlgeschlagen: Host ist nicht erreichbar.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 200);
            }
            $_SESSION['error'] = $message;
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
            $message = 'Verbindung fehlgeschlagen: Host ist nicht erreichbar.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 200);
            }
            $_SESSION['error'] = $message;
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }

        stream_set_timeout($socket, 5);
        $greeting = fgets($socket, 512);
        fclose($socket);

        if ($greeting !== false && str_starts_with($greeting, '* ')) {
            $message = 'Verbindung erfolgreich.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => true, 'message' => $message], 200);
            }
            $_SESSION['success'] = $message;
        } else {
            $message = 'Verbindung fehlgeschlagen: keine gültige IMAP-Antwort erhalten.';
            if ($wantsJson) {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 200);
            }
            $_SESSION['error'] = $message;
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
     * Externe Webmail-URL: nur http(s), gültige URL, max. 255 Zeichen.
     * Liefert null bei gültiger Eingabe, sonst die Fehlermeldung.
     */
    private static function validateExternalWebmailUrl(string $url): ?string
    {
        $isHttp = str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
        if (!$isHttp || strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Bitte gib eine gültige Webmail-URL an (http:// oder https://, max. 255 Zeichen).';
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
