<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use App\Services\MailCredentialCryptoService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MailBadgeServiceTest extends TestCase
{
    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;

    /** @var list<resource> */
    private array $openServerEnds = [];

    protected function setUp(): void
    {
        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $_ENV[self::ENV_KEY] ?? null;

        $generatedKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::ENV_KEY] = $generatedKey;
        $_SERVER[self::ENV_KEY] = $generatedKey;
        putenv(self::ENV_KEY . '=' . $generatedKey);
    }

    protected function tearDown(): void
    {
        foreach ($this->openServerEnds as $server) {
            if (is_resource($server)) {
                fclose($server);
            }
        }
        $this->openServerEnds = [];

        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
            $_SERVER[self::ENV_KEY] = $this->originalEnvValue;
            putenv(self::ENV_KEY . '=' . $this->originalEnvValue);
        } else {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
            putenv(self::ENV_KEY);
        }
    }

    public function testQuoteImapStringWrapsPlainValueInQuotes(): void
    {
        $this->assertSame('"plainvalue"', MailBadgeService::quoteImapString('plainvalue'));
    }

    public function testQuoteImapStringEscapesBackslashesAndQuotes(): void
    {
        $input = 'pass"with\\stuff';

        $quoted = MailBadgeService::quoteImapString($input);

        $this->assertSame('"pass\\"with\\\\stuff"', $quoted);

        // Manually reverse the escaping (strip surrounding quotes, unescape \\ and \")
        // to prove the original value is recoverable.
        $inner = substr($quoted, 1, -1);
        $recovered = str_replace(['\\\\', '\\"'], ['\\', '"'], $inner);
        $this->assertSame($input, $recovered);
    }

    public function testQuoteImapStringRejectsCrlfToPreventCommandInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailBadgeService::quoteImapString("user\r\nA2 LOGOUT");
    }

    public function testParseStatusLineExtractsUnseenAndUidnextInGivenOrder(): void
    {
        $line = "* STATUS INBOX (UNSEEN 5 UIDNEXT 124)\r\n";

        $result = MailBadgeService::parseStatusLine($line);

        $this->assertSame(['unseen' => 5, 'uidnext' => 124], $result);
    }

    public function testParseStatusLineExtractsUnseenAndUidnextInReverseOrder(): void
    {
        $line = "* STATUS INBOX (UIDNEXT 124 UNSEEN 5)\r\n";

        $result = MailBadgeService::parseStatusLine($line);

        $this->assertSame(['unseen' => 5, 'uidnext' => 124], $result);
    }

    public function testParseStatusLineReturnsNullForNonMatchingLine(): void
    {
        $line = "* CAPABILITY IMAP4rev1 LOGINDISABLED\r\n";

        $result = MailBadgeService::parseStatusLine($line);

        $this->assertNull($result);
    }

    public function testRefreshReturnsFalseAndLeavesCacheUntouchedOnConnectionFailure(): void
    {
        $crypto = new MailCredentialCryptoService();
        $service = new MailBadgeService($crypto, new NullLogger(), 1);

        $account = new UserMailAccount();
        $account->user_id = 1;
        $account->imap_host = '127.0.0.1';
        // Port 1 is reserved (tcpmux) and reliably refused/unreachable on loopback in test envs.
        $account->imap_port = 1;
        // Verschlüsselt, damit dieser Fall weiter am Verbindungsfehler scheitert und
        // nicht schon an der Weigerung, unverschlüsselt anzumelden.
        $account->imap_encryption = 'ssl';
        $account->imap_username = 'someone@example.test';
        $account->imap_password_enc = $crypto->encrypt('irrelevant-password');
        $account->mail_last_unseen_count = 7;
        $account->mail_last_uid_seen = '99';
        $account->mail_last_checked_at = null;

        $result = $service->refresh($account);

        $this->assertFalse($result);
        $this->assertSame(7, $account->mail_last_unseen_count);
        $this->assertSame('99', $account->mail_last_uid_seen);
        $this->assertNull($account->mail_last_checked_at);
    }

    /**
     * Ein Altbestand mit `imap_encryption = 'none'`.
     *
     * Das Profilformular lässt diesen Wert nicht mehr speichern und warnt davor, der
     * Hintergrunddienst weigerte sich aber nicht: Für so einen Zugang schickte er LOGIN
     * samt Mailbox-Passwort im Klartext über das Netz. Jetzt bricht er vorher ab - ohne
     * Verbindung, damit das Passwort den Prozess gar nicht verlässt - und schreibt den
     * Grund ins Protokoll, weil sonst niemand erklären kann, warum das Abzeichen steht.
     */
    public function testRefreshRefusesAnAccountWithoutEncryption(): void
    {
        $logger = new class extends NullLogger {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };

        $crypto = new MailCredentialCryptoService();
        $service = new MailBadgeService($crypto, $logger, 1);

        $account = $this->accountWithEncryption($crypto, 'none');

        $this->assertFalse($service->refresh($account));
        $this->assertSame(7, $account->mail_last_unseen_count);
        $this->assertNull($account->mail_last_checked_at);

        $reasons = array_column($logger->records, 'reason');
        $this->assertContains(
            'encryption_required',
            $reasons,
            'Der Grund gehört ins Protokoll, sonst bleibt das stehende Abzeichen unerklärlich.'
        );
    }

    /**
     * Ein leerer oder unbekannter Wert in der Spalte zählt wie "keine Verschlüsselung":
     * Nur `ssl` und `tls` bauen nachweislich eine verschlüsselte Verbindung auf, alles
     * andere ist unbekannt - und Unbekanntes darf kein Passwort tragen.
     */
    public function testRefreshRefusesAnUnknownEncryptionValue(): void
    {
        $crypto = new MailCredentialCryptoService();
        $service = new MailBadgeService($crypto, new NullLogger(), 1);

        foreach (['', 'plain', 'STARTTLS'] as $value) {
            $this->assertFalse(
                $service->refresh($this->accountWithEncryption($crypto, $value)),
                'Wert: ' . $value
            );
        }
    }

    /**
     * Ein Host, den die Absicherung gegen Zugriffe ins interne Netz abweist.
     *
     * Ohne Grund im Protokoll sah das genauso aus wie ein falsches Passwort, obwohl
     * hier gar keine Verbindung aufgebaut wurde.
     */
    public function testRefreshLogsWhyTheHostWasRejected(): void
    {
        $logger = $this->recordingLogger();
        $service = new MailBadgeService(new MailCredentialCryptoService(), $logger, 1);

        $account = $this->accountWithEncryption(new MailCredentialCryptoService(), 'ssl');
        $account->imap_host = '127.0.0.1';

        $this->assertFalse($service->refresh($account));

        $record = $this->singleFailure($logger);
        $this->assertSame('host_rejected', $record['reason']);
        $this->assertStringContainsString('non-public address', (string) $record['detail']);
        $this->assertSame('127.0.0.1', $record['imap_host']);
    }

    /**
     * Die Seed-Daten tragen `imap.example.org` ein. Reservierte Beispiel-Namen (RFC 2606/6761)
     * lösen nie auf; jeder Abgleich einer geseedeten Instanz endete deshalb in einer Warnung,
     * die nach einem Fehler im Betrieb aussah. Solche Hosts werden gar nicht erst versucht:
     * keine Verbindung, keine Warnung, der zwischengespeicherte Stand bleibt.
     */
    public function testRefreshSkipsReservedExampleHostsWithoutWarning(): void
    {
        $hosts = ['imap.example.org', 'example.com', 'mail.example.net', 'IMAP.Example.ORG.', 'mx.chor.test',
            'imap.invalid', 'host.example'];
        foreach ($hosts as $host) {
            $logger = $this->recordingLogger();
            $connected = false;
            $crypto = new MailCredentialCryptoService();
            $service = new MailBadgeService($crypto, $logger, 1, function () use (&$connected) {
                $connected = true;
                throw new \RuntimeException('Darf nicht verbinden.');
            });

            $account = $this->accountWithEncryption($crypto, 'ssl');
            $account->imap_host = $host;

            $this->assertFalse($service->refresh($account), 'Host: ' . $host);
            $this->assertFalse($connected, 'Host: ' . $host);
            $this->assertSame(7, $account->mail_last_unseen_count);
            $warnings = array_filter(
                $logger->records ?? [],
                static fn (array $context): bool => ($context['event'] ?? null) === 'mail_badge.refresh.failed'
            );
            $this->assertSame([], $warnings, 'Host: ' . $host);
        }
    }

    /**
     * Nur der reservierte Name selbst und seine Unterdomänen - ein Host, der bloß so
     * ähnlich heißt, ist ein echter Server und wird weiter abgefragt.
     */
    public function testRefreshStillTriesHostsThatMerelyResembleExampleDomains(): void
    {
        foreach (['notexample.org', 'example.org.at', 'imap.mytest.at'] as $host) {
            $logger = $this->recordingLogger();
            // Ob der Name auflöst oder nicht: Beide Wege enden in einer Warnung. Der
            // eingesetzte Server schließt sofort, damit nichts übers Netz geht.
            $service = new MailBadgeService(
                new MailCredentialCryptoService(),
                $logger,
                1,
                $this->scriptedServer([], true)
            );
            $account = $this->accountWithEncryption(new MailCredentialCryptoService(), 'ssl');
            $account->imap_host = $host;

            $this->assertFalse($service->refresh($account));
            $this->assertNotSame([], array_filter(
                $logger->records ?? [],
                static fn (array $context): bool => ($context['event'] ?? null) === 'mail_badge.refresh.failed'
            ), 'Host: ' . $host);
        }
    }

    /**
     * Ein Verbindungsfehler trägt die Meldung des Betriebssystems mit - nur sie
     * unterscheidet "abgewiesen" von "nicht erreichbar".
     */
    public function testRefreshLogsTheSocketErrorWhenTheConnectionFails(): void
    {
        $logger = $this->recordingLogger();
        $service = new MailBadgeService(new MailCredentialCryptoService(), $logger, 1);

        $account = $this->accountWithEncryption(new MailCredentialCryptoService(), 'ssl');
        $account->imap_host = '127.0.0.1';
        // Port 1 ist auf dem Loopback zuverlässig geschlossen.
        $account->imap_port = 1;

        $this->withPrivateHostsAllowed(function () use ($service, $account): void {
            $this->assertFalse($service->refresh($account));
        });

        $record = $this->singleFailure($logger);
        $this->assertSame('connect_failed', $record['reason']);
        $this->assertNotSame('', (string) $record['detail']);
        $this->assertArrayHasKey('errno', $record);
        $this->assertSame(1, $record['imap_port']);
    }

    /**
     * Ein Server, der sofort mit BYE ablehnt - etwa bei zu vielen gleichzeitigen
     * Verbindungen. Bisher ging der Dienst darüber hinweg und scheiterte erst beim
     * LOGIN, mit einem Fehler, der in die falsche Richtung zeigte.
     */
    public function testRefreshLogsAGreetingThatRefusesTheConnection(): void
    {
        $record = $this->failureAgainstServer('ssl', ["* BYE Too many connections\r\n"]);

        $this->assertSame('greeting_failed', $record['reason']);
        $this->assertSame('* BYE Too many connections', $record['detail']);
    }

    public function testRefreshLogsAGreetingTimeout(): void
    {
        $record = $this->failureAgainstServer('ssl', []);

        $this->assertSame('greeting_failed', $record['reason']);
        $this->assertSame('timed_out', $record['detail']);
    }

    public function testRefreshLogsAConnectionClosedBeforeTheGreeting(): void
    {
        $record = $this->failureAgainstServer('ssl', [], true);

        $this->assertSame('greeting_failed', $record['reason']);
        $this->assertSame('connection_closed', $record['detail']);
    }

    public function testRefreshLogsARejectedStarttls(): void
    {
        $record = $this->failureAgainstServer('tls', [
            "* OK IMAP ready\r\n",
            "A0 NO STARTTLS not available\r\n",
        ]);

        $this->assertSame('starttls_failed', $record['reason']);
        $this->assertSame('A0 NO STARTTLS not available', $record['detail']);
    }

    /**
     * Ein Angreifer in der Leitung kann Zeilen hinter das `A0 OK` schreiben, bevor
     * die Verschlüsselung steht. Sie liegen dann schon im Puffer und läsen sich nach
     * dem Handschlag wie Antworten des echten Servers - der klassische
     * STARTTLS-Einschub (CVE-2011-0411). Entdeckt wird er daran, dass vor dem
     * Handschlag noch Ungelesenes im Puffer steht; ein regelkonformer Server
     * schickt nach dem `A0 OK` nichts mehr.
     */
    public function testRefreshRejectsLinesInjectedBeforeTheTlsHandshake(): void
    {
        $record = $this->failureAgainstServer('tls', [
            "* OK IMAP ready\r\n",
            "A0 OK Begin TLS negotiation now\r\n",
            "A1 OK logged in\r\n",
            "* STATUS INBOX (UNSEEN 0 UIDNEXT 1)\r\n",
            "A2 OK STATUS completed\r\n",
        ]);

        $this->assertSame('starttls_injection', $record['reason']);
    }

    /**
     * Die Gegenprobe: Ein Server, der sich an die Regel hält, darf nicht an der
     * Einschub-Prüfung scheitern - er kommt bis zum Handschlag.
     */
    public function testRefreshReachesTheHandshakeWhenNothingWasInjected(): void
    {
        $record = $this->failureAgainstServer('tls', [
            "* OK IMAP ready\r\n",
            "A0 OK Begin TLS negotiation now\r\n",
        ]);

        $this->assertSame('tls_handshake_failed', $record['reason']);
    }

    /**
     * Die Antwort des Servers auf LOGIN nennt den Grund - und das Passwort darf
     * dabei an keiner Stelle des Protokolleintrags auftauchen.
     */
    public function testRefreshLogsTheServerAnswerToARejectedLoginWithoutThePassword(): void
    {
        $record = $this->failureAgainstServer('ssl', [
            "* OK IMAP ready\r\n",
            "A1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n",
        ]);

        $this->assertSame('login_failed', $record['reason']);
        $this->assertSame('A1 NO [AUTHENTICATIONFAILED] Invalid credentials', $record['detail']);
        $this->assertStringNotContainsString('irrelevant-password', (string) json_encode($record));
    }

    public function testRefreshLogsTheServerAnswerToAFailedStatus(): void
    {
        $record = $this->failureAgainstServer('ssl', [
            "* OK IMAP ready\r\n",
            "A1 OK logged in\r\n",
            "A2 NO Mailbox does not exist\r\n",
        ]);

        $this->assertSame('status_failed', $record['reason']);
        $this->assertSame('A2 NO Mailbox does not exist', $record['detail']);
    }

    public function testRefreshLogsAStatusAnswerWithoutStatusLine(): void
    {
        $record = $this->failureAgainstServer('ssl', [
            "* OK IMAP ready\r\n",
            "A1 OK logged in\r\n",
            "A2 OK done\r\n",
        ]);

        $this->assertSame('status_failed', $record['reason']);
        $this->assertSame('status_line_missing', $record['detail']);
    }

    /**
     * Eine Serverantwort landet nur gekürzt und ohne Steuerzeichen im Protokoll: Sie
     * kommt von außen und darf weder die Zeile sprengen noch das Log fluten.
     */
    public function testRefreshShortensAndCleansTheServerAnswer(): void
    {
        $record = $this->failureAgainstServer('ssl', ["* BYE \x1b[31m" . str_repeat('x', 500) . "\r\n"]);

        $this->assertSame('greeting_failed', $record['reason']);
        $detail = (string) $record['detail'];
        $this->assertStringStartsWith('* BYE [31mxxx', $detail);
        $this->assertLessThanOrEqual(200, mb_strlen($detail));
    }

    /**
     * Alles Unerwartete - hier ein Passwort, das sich nicht entschlüsseln lässt -
     * kommt mit der Ausnahme selbst ins Protokoll, nicht nur mit "fehlgeschlagen".
     */
    public function testRefreshLogsAnUnexpectedExceptionWithTheException(): void
    {
        $logger = $this->recordingLogger();
        $service = new MailBadgeService(
            new MailCredentialCryptoService(),
            $logger,
            1,
            $this->scriptedServer(["* OK IMAP ready\r\n"])
        );

        $account = $this->accountWithEncryption(new MailCredentialCryptoService(), 'ssl');
        $account->imap_host = '1.1.1.1';
        $account->imap_password_enc = 'kaputter-chiffretext';

        $this->assertFalse($service->refresh($account));

        $record = $this->singleFailure($logger);
        $this->assertSame('unexpected_exception', $record['reason']);
        $this->assertInstanceOf(\Throwable::class, $record['exception']);
    }

    /**
     * @param list<string> $serverLines
     * @return array<string, mixed>
     */
    private function failureAgainstServer(string $encryption, array $serverLines, bool $closeAfterwards = false): array
    {
        $logger = $this->recordingLogger();
        $crypto = new MailCredentialCryptoService();
        $service = new MailBadgeService(
            $crypto,
            $logger,
            1,
            $this->scriptedServer($serverLines, $closeAfterwards)
        );

        $account = $this->accountWithEncryption($crypto, $encryption);
        // Eine öffentliche Adresse, damit die Prüfung des Hosts durchgeht; verbunden
        // wird über den eingesetzten Verbinder, nicht übers Netz.
        $account->imap_host = '1.1.1.1';

        $this->assertFalse($service->refresh($account));
        $this->assertSame(7, $account->mail_last_unseen_count);

        return $this->singleFailure($logger);
    }

    /**
     * Ein IMAP-Server aus der Konserve: Seine Antworten liegen schon im Puffer, bevor
     * der Dienst die erste Zeile liest. Was der Dienst schreibt, bleibt ungelesen.
     *
     * @param list<string> $serverLines
     */
    private function scriptedServer(array $serverLines, bool $closeAfterwards = false): \Closure
    {
        return function () use ($serverLines, $closeAfterwards) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertIsArray($pair);
            [$client, $server] = $pair;

            foreach ($serverLines as $line) {
                fwrite($server, $line);
            }

            if ($closeAfterwards) {
                fclose($server);
            } else {
                // Offen halten, solange der Test läuft - sonst läse der Dienst ein
                // Verbindungsende statt einer Zeitüberschreitung.
                $this->openServerEnds[] = $server;
            }

            return $client;
        };
    }

    private function withPrivateHostsAllowed(\Closure $callback): void
    {
        $key = 'MAIL_ALLOW_PRIVATE_HOSTS';
        $hadValue = array_key_exists($key, $_ENV);
        $previous = $_ENV[$key] ?? null;
        $_ENV[$key] = '1';

        try {
            $callback();
        } finally {
            if ($hadValue) {
                $_ENV[$key] = $previous;
            } else {
                unset($_ENV[$key]);
            }
        }
    }

    private function recordingLogger(): NullLogger
    {
        return new class extends NullLogger {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function singleFailure(NullLogger $logger): array
    {
        /** @var array<int, array<string, mixed>> $records */
        $records = $logger->records ?? [];
        $failures = array_values(array_filter(
            $records,
            static fn (array $context): bool => ($context['event'] ?? null) === 'mail_badge.refresh.failed'
        ));

        $this->assertCount(1, $failures, 'Genau ein Protokolleintrag pro gescheitertem Abgleich.');
        $this->assertArrayHasKey('reason', $failures[0], 'Ein Fehlschlag ohne Grund hilft niemandem.');
        $this->assertSame(1, $failures[0]['user_id']);

        return $failures[0];
    }

    private function accountWithEncryption(
        MailCredentialCryptoService $crypto,
        string $encryption
    ): UserMailAccount {
        $account = new UserMailAccount();
        $account->user_id = 1;
        $account->imap_host = 'imap.example.test';
        $account->imap_port = 143;
        $account->imap_encryption = $encryption;
        $account->imap_username = 'someone@example.test';
        $account->imap_password_enc = $crypto->encrypt('irrelevant-password');
        $account->mail_last_unseen_count = 7;
        $account->mail_last_uid_seen = '99';
        $account->mail_last_checked_at = null;

        return $account;
    }
}
