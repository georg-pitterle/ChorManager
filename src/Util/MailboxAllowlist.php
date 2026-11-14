<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Welche Postfächer ein Mitglied im Profil hinterlegen darf.
 *
 * Zwei Listen aus der .env: die Domains der Mailadressen und die Mailserver.
 * Beide müssen passen. Die Domain allein reichte nicht - das Webmail hält seine
 * Server-Konfiguration je Domain, nicht je Person, und das Anmeldezeugnis aus
 * WebmailController::start() überschreibt sie mit dem Host aus dem Profil. Wer
 * dort zu einer erlaubten Adresse einen eigenen Server eintragen konnte, bog
 * damit die Konfiguration der ganzen Domain um und bekam die Zugangsdaten aller
 * anderen mit derselben Domain zugeschickt.
 *
 * Verglichen wird auf den ganzen Namen: Eine Subdomain ist eine andere Domain
 * und ein anderer Host. Leere Listen geben nichts frei - sonst öffnete eine
 * vergessene Zeile in der .env die Lücke wieder.
 */
final class MailboxAllowlist
{
    public const DOMAINS_ENV = 'MAIL_ALLOWED_DOMAINS';
    public const HOSTS_ENV = 'MAIL_ALLOWED_HOSTS';

    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_ADDRESS_DOMAIN = 'address_domain';
    public const REASON_IMAP_HOST = 'imap_host';
    public const REASON_SMTP_HOST = 'smtp_host';

    private const MESSAGES = [
        self::REASON_NOT_CONFIGURED => 'Für diese Installation sind noch keine Postfächer freigegeben. '
            . 'Bitte wende dich an den Administrator.',
        self::REASON_ADDRESS_DOMAIN => 'Diese Mailadresse ist nicht freigegeben. '
            . 'Als Benutzername ist nur eine Adresse des Chors zugelassen.',
        self::REASON_IMAP_HOST => 'Dieser Mailserver ist nicht freigegeben. '
            . 'Bitte trage den Server des Chors als Host ein.',
        self::REASON_SMTP_HOST => 'Dieser SMTP-Server ist nicht freigegeben. '
            . 'Bitte trage den Server des Chors als SMTP-Host ein.',
    ];

    /** @var list<string> */
    private array $domains;

    /** @var list<string> */
    private array $hosts;

    /**
     * @param array<int, string> $domains
     * @param array<int, string> $hosts
     */
    public function __construct(array $domains, array $hosts)
    {
        $this->domains = self::normalizeList($domains);
        $this->hosts = self::normalizeList($hosts);
    }

    public static function fromEnv(): self
    {
        return new self(
            explode(',', EnvHelper::read(self::DOMAINS_ENV, '')),
            explode(',', EnvHelper::read(self::HOSTS_ENV, ''))
        );
    }

    /**
     * @return list<string>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        return $this->hosts;
    }

    public function isConfigured(): bool
    {
        return $this->domains !== [] && $this->hosts !== [];
    }

    /**
     * Warum dieser Zugang nicht zulässig ist - oder null, wenn er es ist.
     */
    public function rejectAccount(string $username, string $imapHost, ?string $smtpHost): ?string
    {
        if (!$this->isConfigured()) {
            return self::REASON_NOT_CONFIGURED;
        }

        if (!$this->isAllowedAddress($username)) {
            return self::REASON_ADDRESS_DOMAIN;
        }

        if (!$this->isAllowedHost($imapHost)) {
            return self::REASON_IMAP_HOST;
        }

        if (trim((string) $smtpHost) !== '' && !$this->isAllowedHost((string) $smtpHost)) {
            return self::REASON_SMTP_HOST;
        }

        return null;
    }

    /**
     * Für den Verbindungstest, der nur einen Host kennt.
     */
    public function rejectHost(string $host): ?string
    {
        if (!$this->isConfigured()) {
            return self::REASON_NOT_CONFIGURED;
        }

        return $this->isAllowedHost($host) ? null : self::REASON_IMAP_HOST;
    }

    public static function messageFor(string $reason): string
    {
        return self::MESSAGES[$reason] ?? self::MESSAGES[self::REASON_NOT_CONFIGURED];
    }

    private function isAllowedAddress(string $username): bool
    {
        $username = trim($username);
        $at = strrpos($username, '@');
        if ($at === false || $at === 0) {
            return false;
        }

        return in_array(self::normalize(substr($username, $at + 1)), $this->domains, true);
    }

    private function isAllowedHost(string $host): bool
    {
        return in_array(self::normalize($host), $this->hosts, true);
    }

    /**
     * @param array<int, string> $entries
     * @return list<string>
     */
    private static function normalizeList(array $entries): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $value = self::normalize((string) $entry);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
