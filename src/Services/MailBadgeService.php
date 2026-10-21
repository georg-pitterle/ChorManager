<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserMailAccount;
use App\Util\BlockedHostException;
use App\Util\OutboundConnectionGuard;
use Carbon\Carbon;
use Closure;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the cached unread-mail badge metadata for a user's mailbox.
 *
 * Uses a minimal hand-rolled IMAP client (no ext-imap available in this
 * environment) that only ever issues LOGIN, STATUS, and LOGOUT against the
 * user's INBOX to read unread-message metadata. It never fetches message
 * bodies. Any failure (network, auth, protocol) is caught internally and
 * leaves the account's previously cached columns untouched, so a flaky or
 * unreachable IMAP server degrades gracefully instead of breaking the UI.
 */
class MailBadgeService
{
    /**
     * Hard cap on the number of lines read per tagged-response/STATUS read
     * loop. Bounds worst-case worker time if a misbehaving or malicious IMAP
     * server streams a continuous flow of short, non-matching, non-EOF lines
     * without ever tripping stream_set_timeout(). Generous enough for any
     * legitimate IMAP greeting/LOGIN/STATUS exchange (normally a handful of
     * lines).
     */
    private const MAX_RESPONSE_LINES = 200;

    /**
     * Höchstlänge einer Serverantwort im Protokoll. Die Zeile kommt von außen; sie
     * soll den Grund zeigen, nicht das Log fluten.
     */
    private const MAX_DETAIL_LENGTH = 200;

    /**
     * Nur diese beiden Werte bauen nachweislich eine verschlüsselte Verbindung auf:
     * `ssl` von der ersten Zeile an, `tls` über STARTTLS vor dem LOGIN.
     *
     * Alles andere - der Altbestand `none`, ein leerer Wert, ein Tippfehler - heißt
     * Klartext, und im Klartext geht hier ein Mailbox-Passwort über das Netz. Das
     * Profilformular lässt `none` seit längerem nicht mehr speichern und warnt beim
     * Altbestand davor; dieser Dienst lief trotzdem weiter und war damit die letzte
     * Stelle, an der ein solcher Zugang noch Schaden anrichten konnte.
     *
     * @var list<string>
     */
    private const ENCRYPTED_TRANSPORTS = ['ssl', 'tls'];

    private MailCredentialCryptoService $crypto;
    private LoggerInterface $logger;
    private int $connectTimeoutSeconds;

    /**
     * Baut die Verbindung auf. Im Betrieb ist das `stream_socket_client`; Tests
     * setzen hier einen IMAP-Server aus der Konserve ein, um jeden Fehlerweg des
     * Gesprächs gezielt anzusteuern.
     *
     * @var Closure(string, mixed): resource
     */
    private Closure $connector;

    /**
     * @param (Closure(string, mixed): resource)|null $connector
     */
    public function __construct(
        MailCredentialCryptoService $crypto,
        LoggerInterface $logger,
        int $connectTimeoutSeconds = 5,
        ?Closure $connector = null
    ) {
        $this->crypto = $crypto;
        $this->logger = $logger;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->connector = $connector ?? $this->connectOverNetwork(...);
    }

    /**
     * Refresh the cached unread-count metadata for the given account.
     *
     * Returns true on a successful update, false on any failure. Never
     * throws. On failure, the account's existing cached columns are left
     * untouched.
     */
    public function refresh(UserMailAccount $account): bool
    {
        $socket = null;

        try {
            $host = (string) $account->imap_host;
            $encryption = strtolower(trim((string) $account->imap_encryption));

            // Vor allem anderen und vor jeder Verbindung: Ohne Verschlüsselung wird das
            // Passwort nicht angefasst, es verlässt den Prozess gar nicht.
            if (!in_array($encryption, self::ENCRYPTED_TRANSPORTS, true)) {
                throw new MailBadgeRefreshException('encryption_required');
            }

            try {
                $validatedIp = OutboundConnectionGuard::resolvePublicIp($host);
            } catch (BlockedHostException $exception) {
                throw new MailBadgeRefreshException('host_rejected', ['detail' => $exception->getMessage()]);
            }

            $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
            // Connect to the validated IP (pinned against DNS rebinding) while
            // verifying TLS against the configured hostname.
            $ipForUrl = str_contains($validatedIp, ':') ? '[' . $validatedIp . ']' : $validatedIp;
            $remote = $scheme . '://' . $ipForUrl . ':' . $account->imap_port;

            $context = stream_context_create([
                'ssl' => [
                    'peer_name' => $host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                ],
            ]);

            $socket = ($this->connector)($remote, $context);

            stream_set_timeout($socket, $this->connectTimeoutSeconds);

            $password = $this->crypto->decrypt((string) $account->imap_password_enc);

            // `* OK` und nichts anderes: Ein `* BYE` - etwa bei zu vielen gleichzeitigen
            // Verbindungen - ist eine Absage, und darüber hinwegzugehen hieße, erst beim
            // LOGIN zu scheitern, mit einem Grund, der in die falsche Richtung zeigt.
            $greeting = $this->readLine($socket, 'greeting_failed');
            if (!str_starts_with($greeting, '* OK')) {
                throw new MailBadgeRefreshException('greeting_failed', ['detail' => self::excerpt($greeting)]);
            }

            // For STARTTLS accounts, upgrade the plain connection to TLS BEFORE
            // any credential leaves the process - otherwise LOGIN would travel
            // in cleartext.
            if ($encryption === 'tls') {
                $this->sendCommand($socket, "A0 STARTTLS\r\n", 'starttls_failed');
                $this->expectTaggedOk($socket, 'A0', 'starttls_failed');

                // Nach dem `A0 OK` schickt ein regelkonformer Server nichts mehr, bis
                // die Verschlüsselung steht (RFC 3501 §6.2.1). Steht trotzdem noch
                // etwas im Puffer, hat es jemand in der noch offenen Leitung
                // nachgeschoben: Nach dem Handschlag läse sich dieser Vorrat wie eine
                // Antwort des echten Servers, und ein eingeschobenes `A1 OK` machte aus
                // einer nie bestätigten Anmeldung eine bestätigte. Der klassische
                // STARTTLS-Einschub (CVE-2011-0411); hier endet er vor dem Handschlag.
                $pending = (int) (stream_get_meta_data($socket)['unread_bytes'] ?? 0);
                if ($pending > 0) {
                    throw new MailBadgeRefreshException(
                        'starttls_injection',
                        ['detail' => 'pending_bytes=' . $pending]
                    );
                }

                error_clear_last();
                $cryptoEnabled = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if ($cryptoEnabled !== true) {
                    throw new MailBadgeRefreshException(
                        'tls_handshake_failed',
                        ['detail' => error_get_last()['message'] ?? 'unknown']
                    );
                }
            }

            $loginCommand = 'A1 LOGIN ' . self::quoteImapString((string) $account->imap_username)
                . ' ' . self::quoteImapString($password) . "\r\n";

            $this->sendCommand($socket, $loginCommand, 'login_failed');
            $this->expectTaggedOk($socket, 'A1', 'login_failed');

            $this->sendCommand($socket, "A2 STATUS INBOX (UNSEEN UIDNEXT)\r\n", 'status_failed');
            $statusResult = $this->readStatusResponse($socket);

            // Best-effort: refresh already succeeded regardless of LOGOUT outcome.
            @fwrite($socket, "A3 LOGOUT\r\n");

            $uidnext = $statusResult['uidnext'];
            $highestUid = $uidnext > 0 ? $uidnext - 1 : 0;

            $account->mail_last_unseen_count = $statusResult['unseen'];
            $account->mail_last_uid_seen = (string) $highestUid;
            $account->mail_last_checked_at = Carbon::now();
            $account->save();

            return true;
        } catch (MailBadgeRefreshException $exception) {
            $this->logFailure($account, $exception->reason, $exception->context);
            return false;
        } catch (Throwable $exception) {
            $this->logFailure($account, 'unexpected_exception', ['exception' => $exception]);
            return false;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * Wrap a value in IMAP quoted-string syntax (RFC 3501 §4.3), escaping
     * embedded backslashes and double quotes.
     *
     * RFC 3501 forbids CR and LF inside a quoted-string; a value carrying them
     * could break out of the quotes and inject a separate IMAP command. Such a
     * value is never legitimate in a username or password, so we fail closed.
     *
     * @throws InvalidArgumentException when $value contains CR or LF.
     */
    public static function quoteImapString(string $value): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException('IMAP quoted-string must not contain CR or LF.');
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }

    /**
     * Parse an untagged IMAP STATUS response line, extracting UNSEEN and
     * UIDNEXT by key name (order is not guaranteed by the RFC).
     *
     * @return array{unseen: int, uidnext: int}|null
     */
    public static function parseStatusLine(string $line): ?array
    {
        if (!preg_match('/^\*\s+STATUS\s+\S+\s+\(/', $line)) {
            return null;
        }

        $unseenMatch = [];
        $uidnextMatch = [];

        if (!preg_match('/\bUNSEEN\s+(\d+)/i', $line, $unseenMatch)) {
            return null;
        }

        if (!preg_match('/\bUIDNEXT\s+(\d+)/i', $line, $uidnextMatch)) {
            return null;
        }

        return [
            'unseen' => (int) $unseenMatch[1],
            'uidnext' => (int) $uidnextMatch[1],
        ];
    }

    /**
     * @return resource
     */
    private function connectOverNetwork(string $remote, mixed $context)
    {
        $errno = 0;
        $errstr = '';
        error_clear_last();
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            // Bei `ssl` scheitert hier auch die TLS-Aushandlung, etwa an einem
            // abgelaufenen Zertifikat; `errstr` bleibt dann leer, und die Erklärung
            // steht nur in der letzten PHP-Warnung.
            $detail = $errstr !== '' ? $errstr : (error_get_last()['message'] ?? 'unknown');
            throw new MailBadgeRefreshException('connect_failed', ['errno' => $errno, 'detail' => $detail]);
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function sendCommand($socket, string $command, string $reason): void
    {
        if (@fwrite($socket, $command) === false) {
            throw new MailBadgeRefreshException($reason, ['detail' => 'write_failed']);
        }
    }

    /**
     * Eine Zeile vom Server - oder ein Fehlschlag, der sagt, warum keine kam.
     *
     * @param resource $socket
     */
    private function readLine($socket, string $reason): string
    {
        $line = fgets($socket, 1024);
        if ($line !== false) {
            return $line;
        }

        $timedOut = (bool) (stream_get_meta_data($socket)['timed_out'] ?? false);

        throw new MailBadgeRefreshException($reason, ['detail' => $timedOut ? 'timed_out' : 'connection_closed']);
    }

    /**
     * Read lines until the one tagged with $tag, which must be an OK.
     *
     * @param resource $socket
     */
    private function expectTaggedOk($socket, string $tag, string $reason): void
    {
        for ($linesRead = 0; $linesRead < self::MAX_RESPONSE_LINES; $linesRead++) {
            $line = $this->readLine($socket, $reason);

            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with($line, $tag . ' OK')) {
                    throw new MailBadgeRefreshException($reason, ['detail' => self::excerpt($line)]);
                }

                return;
            }
        }

        throw new MailBadgeRefreshException($reason, ['detail' => 'too_many_lines']);
    }

    /**
     * Read lines until the tagged A2 response, capturing the untagged
     * STATUS line along the way.
     *
     * @param resource $socket
     * @return array{unseen: int, uidnext: int}
     */
    private function readStatusResponse($socket): array
    {
        $statusResult = null;

        for ($linesRead = 0; $linesRead < self::MAX_RESPONSE_LINES; $linesRead++) {
            $line = $this->readLine($socket, 'status_failed');

            if (str_starts_with($line, '* STATUS')) {
                $statusResult = self::parseStatusLine($line);
                continue;
            }

            if (str_starts_with($line, 'A2 ')) {
                if (!str_starts_with($line, 'A2 OK')) {
                    throw new MailBadgeRefreshException('status_failed', ['detail' => self::excerpt($line)]);
                }

                if ($statusResult === null) {
                    throw new MailBadgeRefreshException('status_failed', ['detail' => 'status_line_missing']);
                }

                return $statusResult;
            }
        }

        throw new MailBadgeRefreshException('status_failed', ['detail' => 'too_many_lines']);
    }

    /**
     * Eine Serverantwort, so gekürzt und bereinigt, dass sie gefahrlos ins Protokoll
     * kann: ohne Steuerzeichen, die die Zeile sprengen, und nicht beliebig lang.
     */
    private static function excerpt(string $line): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $line));

        return mb_substr($clean, 0, self::MAX_DETAIL_LENGTH);
    }

    /**
     * Ein Fehlschlag, ein Eintrag - immer mit dem Schritt, an dem es scheiterte
     * (`reason`), und mit dem, was ihn erklärt (`detail`, `errno` oder `exception`).
     * Host, Port und Verschlüsselung stehen dabei, weil sie die häufigste Ursache
     * sind und sich ohne sie nicht vom Fehler des Servers trennen lassen. Das
     * Passwort kommt nie hinein: Keine der Angaben stammt aus dem LOGIN-Befehl,
     * nur aus der Antwort des Servers darauf.
     *
     * @param array<string, mixed> $details
     */
    private function logFailure(UserMailAccount $account, string $reason, array $details = []): void
    {
        $context = [
            'event' => 'mail_badge.refresh.failed',
            'user_id' => $account->user_id,
            'reason' => $reason,
            'imap_host' => (string) $account->imap_host,
            'imap_port' => (int) $account->imap_port,
            'imap_encryption' => (string) $account->imap_encryption,
        ] + $details;

        $this->logger->warning('Mail badge refresh failed.', $context);
    }
}
