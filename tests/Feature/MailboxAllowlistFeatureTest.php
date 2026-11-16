<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Controllers\WebmailController;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use App\Services\WebmailSsoTokenService;
use App\Util\MailboxAllowlist;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Freigabeliste an den drei Stellen, an denen ein Mitglied einen Mailserver
 * ins Spiel bringt: Postfach speichern, Verbindung testen, Webmail starten.
 *
 * Hintergrund: Das Webmail hält seine Server-Konfiguration je Domain, nicht je
 * Person. Solange der Host im Profil frei wählbar war, konnte ein Mitglied die
 * Konfiguration einer ganzen Domain auf einen eigenen Server zeigen lassen -
 * und bekam dort die Zugangsdaten aller anderen mit derselben Domain.
 */
final class MailboxAllowlistFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private const ENV_KEYS = ['WEBMAIL_SSO_SECRET', 'MAIL_CREDENTIAL_KEY'];

    private MailCredentialCryptoService $crypto;
    private User $user;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        foreach (self::ENV_KEYS as $key) {
            $this->originalEnv[$key] = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null;
            $this->setEnv($key, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        }

        $this->crypto = new MailCredentialCryptoService();

        $this->user = User::create([
            'first_name' => 'Freigabe',
            'last_name' => 'Tester',
            'email' => 'freigabe.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        $_SESSION = ['user_id' => $this->user->id];
    }

    protected function tearDown(): void
    {
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value);
        }

        parent::tearDown();
    }

    public function testAllowedMailboxIsSaved(): void
    {
        $response = $this->profileControllerWith($this->allowlist())->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form()),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
        $this->assertSame('Mailbox-Einstellungen wurden gespeichert.', $_SESSION['success'] ?? null);
        $this->assertNotNull($this->storedAccount());
    }

    /**
     * Der Angriff selbst: erlaubte Adresse, eigener Server.
     */
    public function testAllowedAddressWithForeignHostIsNotSaved(): void
    {
        $response = $this->profileControllerWith($this->allowlist())->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form(['imap_host' => 'imap.boese.example'])),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
        $this->assertSame(
            MailboxAllowlist::messageFor(MailboxAllowlist::REASON_IMAP_HOST),
            $_SESSION['error'] ?? null
        );
        $this->assertNull($this->storedAccount());
    }

    public function testForeignSmtpHostIsNotSaved(): void
    {
        $this->profileControllerWith($this->allowlist())->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form(['smtp_host' => 'smtp.boese.example'])),
            $this->makeResponse()
        );

        $this->assertSame(
            MailboxAllowlist::messageFor(MailboxAllowlist::REASON_SMTP_HOST),
            $_SESSION['error'] ?? null
        );
        $this->assertNull($this->storedAccount());
    }

    public function testPrivateAddressIsNotSaved(): void
    {
        $this->profileControllerWith($this->allowlist())->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form(['imap_username' => 'ich@privat.example'])),
            $this->makeResponse()
        );

        $this->assertSame(
            MailboxAllowlist::messageFor(MailboxAllowlist::REASON_ADDRESS_DOMAIN),
            $_SESSION['error'] ?? null
        );
        $this->assertNull($this->storedAccount());
    }

    public function testNothingIsSavedWithoutConfiguredLists(): void
    {
        $this->profileControllerWith(new MailboxAllowlist([], []))->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form()),
            $this->makeResponse()
        );

        $this->assertSame(
            MailboxAllowlist::messageFor(MailboxAllowlist::REASON_NOT_CONFIGURED),
            $_SESSION['error'] ?? null
        );
        $this->assertNull($this->storedAccount());
    }

    /**
     * Ein abgelehnter Wechsel lässt den gespeicherten Zugang unberührt - sonst
     * stünde nach dem Fehlversuch doch der fremde Server in der Zeile.
     */
    public function testRejectedChangeLeavesTheStoredAccountUntouched(): void
    {
        $controller = $this->profileControllerWith($this->allowlist());
        $controller->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form()),
            $this->makeResponse()
        );

        $controller->updateMailbox(
            $this->makeRequest('POST', '/profile/mailbox', $this->form(['imap_host' => 'imap.boese.example'])),
            $this->makeResponse()
        );

        $this->assertSame('imap.provider.example', $this->storedAccount()?->imap_host);
    }

    public function testRejectionIsReportedAsJsonToTheFormScript(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->form(['imap_host' => 'imap.boese.example']))
            ->withHeader('Accept', 'application/json');

        $response = $this->profileControllerWith($this->allowlist())->updateMailbox($request, $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(MailboxAllowlist::messageFor(MailboxAllowlist::REASON_IMAP_HOST), $payload['message']);
    }

    /**
     * Der Verbindungstest baut selbst eine Verbindung auf. Zu einem nicht
     * freigegebenen Server geht er gar nicht erst los.
     */
    public function testConnectionTestRefusesForeignHostBeforeConnecting(): void
    {
        $response = $this->profileControllerWith($this->allowlist())->testMailboxConnection(
            $this->makeRequest('POST', '/profile/mailbox/test', $this->form(['imap_host' => 'imap.boese.example'])),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
        $this->assertSame(
            MailboxAllowlist::messageFor(MailboxAllowlist::REASON_IMAP_HOST),
            $_SESSION['error'] ?? null
        );
    }

    public function testWebmailStartsForAllowedMailbox(): void
    {
        $this->storeAccount('vorstand@chor.example', 'imap.provider.example', 'smtp.provider.example');

        $response = $this->webmailController($this->allowlist())->start(
            $this->makeRequest('POST', '/profile/webmail/start'),
            $this->makeResponse()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/webmail/?chormanager-sso&token=', $response->getHeaderLine('Location'));
    }

    /**
     * Zugänge, die vor der Freigabeliste gespeichert wurden, stehen weiter in
     * der Tabelle. Das Webmail bekommt für sie kein Anmeldezeugnis mehr - genau
     * dieses Zeugnis ist es, das die Domain-Konfiguration überschreibt.
     */
    public function testWebmailDoesNotStartForStoredMailboxWithForeignHost(): void
    {
        $this->storeAccount('vorstand@chor.example', 'imap.boese.example', null);

        $response = $this->webmailController($this->allowlist())->start(
            $this->makeRequest('POST', '/profile/webmail/start'),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
        $this->assertStringNotContainsString('token=', $response->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['error'] ?? null);
    }

    public function testWebmailDoesNotStartForStoredMailboxWithForeignSmtpHost(): void
    {
        $this->storeAccount('vorstand@chor.example', 'imap.provider.example', 'smtp.boese.example');

        $response = $this->webmailController($this->allowlist())->start(
            $this->makeRequest('POST', '/profile/webmail/start'),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
    }

    public function testWebmailDoesNotStartForStoredPrivateAddress(): void
    {
        $this->storeAccount('ich@privat.example', 'imap.provider.example', null);

        $response = $this->webmailController($this->allowlist())->start(
            $this->makeRequest('POST', '/profile/webmail/start'),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
    }

    public function testWebmailDoesNotStartWithoutConfiguredLists(): void
    {
        $this->storeAccount('vorstand@chor.example', 'imap.provider.example', null);

        $response = $this->webmailController(new MailboxAllowlist([], []))->start(
            $this->makeRequest('POST', '/profile/webmail/start'),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/profile');
    }

    /**
     * Das Formular nennt, was freigegeben ist - sonst erfährt ein Mitglied erst
     * aus der Fehlermeldung, welchen Server es hätte eintragen sollen.
     */
    public function testProfilePageReceivesTheAllowedDomainsAndHosts(): void
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured) {
                $captured = $data;

                return $response;
            }
        );

        $this->profileControllerWith($this->allowlist(), $twig)->index(
            $this->makeRequest('GET', '/profile'),
            $this->makeResponse()
        );

        $this->assertSame(['chor.example'], $captured['mailbox_allowed_domains'] ?? null);
        $this->assertSame(
            ['imap.provider.example', 'smtp.provider.example'],
            $captured['mailbox_allowed_hosts'] ?? null
        );
    }

    public function testProfileTemplateShowsTheAllowedValues(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/profile/index.twig');

        $this->assertStringContainsString('mailbox_allowed_domains', $template);
        $this->assertStringContainsString('mailbox_allowed_hosts', $template);
    }

    private function allowlist(): MailboxAllowlist
    {
        return new MailboxAllowlist(['chor.example'], ['imap.provider.example', 'smtp.provider.example']);
    }

    private function profileControllerWith(MailboxAllowlist $allowlist, ?Twig $twig = null): ProfileController
    {
        return $this->profileController([
            'view' => $twig ?? $this->createStub(Twig::class),
            'crypto' => $this->crypto,
            'notificationService' => null,
            'mailboxAllowlist' => $allowlist,
        ]);
    }

    private function webmailController(MailboxAllowlist $allowlist): WebmailController
    {
        return new WebmailController(new NullLogger(), $this->crypto, new WebmailSsoTokenService(), $allowlist);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'imap_host' => 'imap.provider.example',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'vorstand@chor.example',
            'imap_password' => 'S3cr3t-Imap-Pass',
            'smtp_host' => 'smtp.provider.example',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'imap_enabled' => '1',
        ];
    }

    private function storeAccount(string $username, string $imapHost, ?string $smtpHost): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => $imapHost,
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpHost === null ? null : 587,
            'smtp_encryption' => $smtpHost === null ? null : 'tls',
            'imap_username' => $username,
            'imap_password_enc' => $this->crypto->encrypt('S3cr3t-Imap-Pass'),
            'imap_enabled' => true,
            'mail_badge_enabled' => false,
        ]);
    }

    private function storedAccount(): ?UserMailAccount
    {
        return UserMailAccount::where('user_id', $this->user->id)->first();
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
