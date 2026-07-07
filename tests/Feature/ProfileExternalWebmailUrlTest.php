<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProfileController;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\PasswordPolicyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class ProfileExternalWebmailUrlTest extends TestCase
{
    use TestHttpHelpers;

    private const CRYPTO_ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ProfileController $controller;
    private User $user;
    private ?string $originalCryptoEnvValue = null;
    private bool $hadCryptoEnvValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->hadCryptoEnvValue = array_key_exists(self::CRYPTO_ENV_KEY, $_ENV);
        $this->originalCryptoEnvValue = $_ENV[self::CRYPTO_ENV_KEY] ?? null;
        $cryptoKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::CRYPTO_ENV_KEY] = $cryptoKey;
        $_SERVER[self::CRYPTO_ENV_KEY] = $cryptoKey;
        putenv(self::CRYPTO_ENV_KEY . '=' . $cryptoKey);

        $this->controller = new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(),
            new PasswordPolicyService(),
            new NullLogger(),
            new MailCredentialCryptoService()
        );

        $this->user = User::create([
            'first_name' => 'Extern',
            'last_name' => 'Webmail',
            'email' => 'extern.webmail.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        // Bestehender Account, damit updateMailbox ohne Passwort auskommt.
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'extern.webmail@example.org',
            'imap_password_enc' => 'dummy-enc-value',
            'imap_enabled' => true,
        ]);

        $_SESSION = [];
        $_SESSION['user_id'] = $this->user->id;
    }

    protected function tearDown(): void
    {
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

        if ($this->hadCryptoEnvValue) {
            $_ENV[self::CRYPTO_ENV_KEY] = $this->originalCryptoEnvValue;
            $_SERVER[self::CRYPTO_ENV_KEY] = $this->originalCryptoEnvValue;
            putenv(self::CRYPTO_ENV_KEY . '=' . $this->originalCryptoEnvValue);
        } else {
            unset($_ENV[self::CRYPTO_ENV_KEY], $_SERVER[self::CRYPTO_ENV_KEY]);
            putenv(self::CRYPTO_ENV_KEY);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function mailboxPostBody(array $overrides = []): array
    {
        return array_merge([
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'extern.webmail@example.org',
            'imap_password' => '',
        ], $overrides);
    }

    public function testValidExternalWebmailUrlIsPersisted(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody([
            'external_webmail_url' => 'https://webmail.example.org/inbox',
        ]));

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertSame('https://webmail.example.org/inbox', $account->external_webmail_url);
        $this->assertArrayHasKey('success', $_SESSION);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testInvalidExternalWebmailUrlIsRejected(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody([
            'external_webmail_url' => 'javascript:alert(1)',
        ]));

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNull($account->external_webmail_url);
        $this->assertArrayHasKey('error', $_SESSION);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testEmptyExternalWebmailUrlClearsStoredValue(): void
    {
        UserMailAccount::where('user_id', $this->user->id)
            ->update(['external_webmail_url' => 'https://webmail.example.org/']);

        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody([
            'external_webmail_url' => '',
        ]));

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNull($account->external_webmail_url);
        unset($_SESSION['success'], $_SESSION['error']);
    }

    public function testMissingExternalWebmailUrlKeyKeepsStoredValue(): void
    {
        UserMailAccount::where('user_id', $this->user->id)
            ->update(['external_webmail_url' => 'https://webmail.example.org/']);

        // Kein external_webmail_url-Key im Body (Feld wird bei aktivem SnappyMail nicht gerendert).
        $request = $this->makeRequest('POST', '/profile/mailbox', $this->mailboxPostBody());

        $this->controller->updateMailbox($request, $this->makeResponse());

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertSame('https://webmail.example.org/', $account->external_webmail_url);
        unset($_SESSION['success'], $_SESSION['error']);
    }
}
