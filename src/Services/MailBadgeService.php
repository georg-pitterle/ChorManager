<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserMailAccount;
use Carbon\Carbon;
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
    private MailCredentialCryptoService $crypto;
    private LoggerInterface $logger;
    private int $connectTimeoutSeconds;

    public function __construct(
        MailCredentialCryptoService $crypto,
        LoggerInterface $logger,
        int $connectTimeoutSeconds = 5
    ) {
        $this->crypto = $crypto;
        $this->logger = $logger;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
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
            $password = $this->crypto->decrypt((string) $account->imap_password_enc);

            $scheme = $account->imap_encryption === 'ssl' ? 'ssl' : 'tcp';
            $remote = $scheme . '://' . $account->imap_host . ':' . $account->imap_port;

            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client($remote, $errno, $errstr, (float) $this->connectTimeoutSeconds);

            if ($socket === false) {
                $this->logFailure($account);
                return false;
            }

            stream_set_timeout($socket, $this->connectTimeoutSeconds);

            $greeting = fgets($socket, 512);
            if ($greeting === false || !str_starts_with($greeting, '* ')) {
                $this->logFailure($account);
                return false;
            }

            $loginCommand = 'A1 LOGIN ' . self::quoteImapString((string) $account->imap_username)
                . ' ' . self::quoteImapString($password) . "\r\n";

            if (!$this->sendCommand($socket, $loginCommand)) {
                $this->logFailure($account);
                return false;
            }

            if (!$this->readUntilTagged($socket, 'A1 ', 'A1 OK')) {
                $this->logFailure($account);
                return false;
            }

            if (!$this->sendCommand($socket, "A2 STATUS INBOX (UNSEEN UIDNEXT)\r\n")) {
                $this->logFailure($account);
                return false;
            }

            $statusResult = $this->readStatusResponse($socket);
            if ($statusResult === null) {
                $this->logFailure($account);
                return false;
            }

            $this->sendCommand($socket, "A3 LOGOUT\r\n");

            $uidnext = $statusResult['uidnext'];
            $highestUid = $uidnext > 0 ? $uidnext - 1 : 0;

            $account->mail_last_unseen_count = $statusResult['unseen'];
            $account->mail_last_uid_seen = (string) $highestUid;
            $account->mail_last_checked_at = Carbon::now();
            $account->save();

            return true;
        } catch (Throwable $exception) {
            $this->logFailure($account);
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
     */
    public static function quoteImapString(string $value): string
    {
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
     * @param resource $socket
     */
    private function sendCommand($socket, string $command): bool
    {
        $written = @fwrite($socket, $command);

        return $written !== false;
    }

    /**
     * Read lines until one starting with $tag is seen, returning whether
     * that tagged line starts with $expectedPrefix.
     *
     * @param resource $socket
     */
    private function readUntilTagged($socket, string $tag, string $expectedPrefix): bool
    {
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                return false;
            }

            if (str_starts_with($line, $tag)) {
                return str_starts_with($line, $expectedPrefix);
            }
        }

        return false;
    }

    /**
     * Read lines until the tagged A2 response, capturing the untagged
     * STATUS line along the way.
     *
     * @param resource $socket
     * @return array{unseen: int, uidnext: int}|null
     */
    private function readStatusResponse($socket): ?array
    {
        $statusResult = null;

        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                return null;
            }

            if (str_starts_with($line, '* STATUS')) {
                $statusResult = self::parseStatusLine($line);
                continue;
            }

            if (str_starts_with($line, 'A2 ')) {
                if (!str_starts_with($line, 'A2 OK') || $statusResult === null) {
                    return null;
                }

                return $statusResult;
            }
        }

        return null;
    }

    private function logFailure(UserMailAccount $account): void
    {
        $this->logger->warning(
            'Mail badge refresh failed.',
            [
                'event' => 'mail_badge.refresh.failed',
                'user_id' => $account->user_id,
            ]
        );
    }
}
