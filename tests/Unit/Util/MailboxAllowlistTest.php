<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\MailboxAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Die Freigabeliste für Postfächer: Adress-Domains und Mailserver, beide aus der
 * .env. Sie ist der Riegel gegen ein Mitglied, das die Webmail-Konfiguration
 * einer ganzen Domain auf einen fremden Server umbiegt.
 */
final class MailboxAllowlistTest extends TestCase
{
    private const ENV_KEYS = [MailboxAllowlist::DOMAINS_ENV, MailboxAllowlist::HOSTS_ENV];

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            $this->originalEnv[$key] = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value);
        }

        parent::tearDown();
    }

    public function testAllowedAddressAndHostsPass(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example', 'smtp.provider.example']);

        $this->assertNull(
            $allowlist->rejectAccount('vorstand@chor.example', 'imap.provider.example', 'smtp.provider.example')
        );
    }

    public function testSmtpHostIsOptional(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        $this->assertNull($allowlist->rejectAccount('vorstand@chor.example', 'imap.provider.example', null));
        $this->assertNull($allowlist->rejectAccount('vorstand@chor.example', 'imap.provider.example', ''));
    }

    public function testComparisonIgnoresCaseAndSurroundingWhitespace(): void
    {
        $allowlist = new MailboxAllowlist([' Chor.Example '], ['IMAP.Provider.Example']);

        $this->assertNull($allowlist->rejectAccount('Vorstand@CHOR.example', ' imap.provider.EXAMPLE ', null));
    }

    public function testForeignAddressDomainIsRejected(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        $this->assertSame(
            MailboxAllowlist::REASON_ADDRESS_DOMAIN,
            $allowlist->rejectAccount('jemand@privat.example', 'imap.provider.example', null)
        );
    }

    /**
     * Eine Subdomain ist eine andere Domain: Wer `chor.example` freigibt, gibt
     * damit nicht `boese.chor.example` frei - und erst recht nicht
     * `chor.example.boese.example`.
     */
    public function testOnlyTheExactDomainCounts(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        foreach (['x@sub.chor.example', 'x@chor.example.boese.example', 'x@nichtchor.example'] as $username) {
            $this->assertSame(
                MailboxAllowlist::REASON_ADDRESS_DOMAIN,
                $allowlist->rejectAccount($username, 'imap.provider.example', null),
                $username
            );
        }
    }

    public function testUsernameWithoutAddressFormIsRejected(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        foreach (['vorstand', '@chor.example', 'vorstand@', ''] as $username) {
            $this->assertSame(
                MailboxAllowlist::REASON_ADDRESS_DOMAIN,
                $allowlist->rejectAccount($username, 'imap.provider.example', null),
                'Benutzername: "' . $username . '"'
            );
        }
    }

    /**
     * Der eigentliche Angriff: eine erlaubte Adresse, aber ein fremder Server.
     */
    public function testAllowedAddressWithForeignImapHostIsRejected(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        $this->assertSame(
            MailboxAllowlist::REASON_IMAP_HOST,
            $allowlist->rejectAccount('vorstand@chor.example', 'imap.boese.example', null)
        );
    }

    public function testOnlyTheExactHostCounts(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        foreach (['x.imap.provider.example', 'imap.provider.example.boese.example', 'provider.example'] as $host) {
            $this->assertSame(MailboxAllowlist::REASON_IMAP_HOST, $allowlist->rejectHost($host), $host);
        }
    }

    public function testForeignSmtpHostIsRejected(): void
    {
        $allowlist = new MailboxAllowlist(['chor.example'], ['imap.provider.example']);

        $this->assertSame(
            MailboxAllowlist::REASON_SMTP_HOST,
            $allowlist->rejectAccount('vorstand@chor.example', 'imap.provider.example', 'smtp.boese.example')
        );
    }

    /**
     * Ohne Angabe ist nichts freigegeben. Eine leere Liste als "alles erlaubt" zu
     * lesen hieße, dass eine vergessene Zeile in der .env die Lücke wieder öffnet.
     */
    public function testEmptyListsAllowNothing(): void
    {
        foreach ([[[], []], [['chor.example'], []], [[], ['imap.provider.example']]] as [$domains, $hosts]) {
            $allowlist = new MailboxAllowlist($domains, $hosts);

            $this->assertFalse($allowlist->isConfigured());
            $this->assertSame(
                MailboxAllowlist::REASON_NOT_CONFIGURED,
                $allowlist->rejectAccount('vorstand@chor.example', 'imap.provider.example', null)
            );
            $this->assertSame(
                MailboxAllowlist::REASON_NOT_CONFIGURED,
                $allowlist->rejectHost('imap.provider.example')
            );
        }
    }

    public function testListsAreReadFromTheEnvironment(): void
    {
        $this->setEnv(MailboxAllowlist::DOMAINS_ENV, 'chor.example, Zweitchor.example ,');
        $this->setEnv(MailboxAllowlist::HOSTS_ENV, 'imap.provider.example,smtp.provider.example');

        $allowlist = MailboxAllowlist::fromEnv();

        $this->assertSame(['chor.example', 'zweitchor.example'], $allowlist->domains());
        $this->assertSame(['imap.provider.example', 'smtp.provider.example'], $allowlist->hosts());
        $this->assertTrue($allowlist->isConfigured());
    }

    public function testMissingEnvironmentMeansNotConfigured(): void
    {
        $this->setEnv(MailboxAllowlist::DOMAINS_ENV, null);
        $this->setEnv(MailboxAllowlist::HOSTS_ENV, null);

        $this->assertFalse(MailboxAllowlist::fromEnv()->isConfigured());
    }

    public function testEveryReasonHasAMessage(): void
    {
        $reasons = [
            MailboxAllowlist::REASON_NOT_CONFIGURED,
            MailboxAllowlist::REASON_ADDRESS_DOMAIN,
            MailboxAllowlist::REASON_IMAP_HOST,
            MailboxAllowlist::REASON_SMTP_HOST,
        ];

        foreach ($reasons as $reason) {
            $this->assertNotSame('', MailboxAllowlist::messageFor($reason), $reason);
        }
    }

    /**
     * Die Vorgabe in .env.example muss die Postfächer aus den Seed-Daten
     * freigeben (DevSeedService::seedUserMailAccounts) - sonst startet in einer
     * frisch aufgesetzten Entwicklungsumgebung kein einziges Webmail.
     */
    public function testExampleEnvironmentAllowsTheSeededMailboxes(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 3) . '/.env.example');

        $this->assertSame(1, preg_match('/^' . MailboxAllowlist::DOMAINS_ENV . '=(.*)$/m', $example, $domains));
        $this->assertSame(1, preg_match('/^' . MailboxAllowlist::HOSTS_ENV . '=(.*)$/m', $example, $hosts));

        $allowlist = new MailboxAllowlist(explode(',', $domains[1]), explode(',', $hosts[1]));

        $this->assertNull(
            $allowlist->rejectAccount('seed.001@chor.local', 'imap.example.org', 'smtp.example.org')
        );
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}
