<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MailBadgeController;
use App\Controllers\WebmailController;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\MailCredentialCryptoService;
use App\Services\WebmailSsoTokenService;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

final class WebmailControllerFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const SSO_ENV_KEY = 'WEBMAIL_SSO_SECRET';

    /**
     * Der Schlüssel, ohne den MailCredentialCryptoService seinen Dienst verweigert.
     * Er kam bisher aus der .env - die Klasse setzte also voraus, dass keine andere
     * Testklasse ihn im selben Prozess entfernt. Genau das geschah, und im parallelen
     * Lauf fiel es je nach Aufteilung der Klassen auf die Prozesse auf.
     */
    private const CRYPTO_ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private WebmailController $controller;
    private MailCredentialCryptoService $crypto;
    private WebmailSsoTokenService $ssoTokenService;
    private User $user;
    private ?string $originalSsoEnvValue = null;
    private bool $hadSsoEnvValue = false;
    private ?string $originalCryptoEnvValue = null;
    private bool $hadCryptoEnvValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->hadSsoEnvValue = array_key_exists(self::SSO_ENV_KEY, $_ENV);
        $this->originalSsoEnvValue = $_ENV[self::SSO_ENV_KEY] ?? null;

        $this->hadCryptoEnvValue = array_key_exists(self::CRYPTO_ENV_KEY, $_ENV);
        $this->originalCryptoEnvValue = $_ENV[self::CRYPTO_ENV_KEY] ?? null;

        $ssoKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::SSO_ENV_KEY] = $ssoKey;
        $_SERVER[self::SSO_ENV_KEY] = $ssoKey;
        putenv(self::SSO_ENV_KEY . '=' . $ssoKey);

        // Eigener Schlüssel statt des Werts aus der .env: damit hängt die Klasse an
        // nichts, was ein Vorgänger im selben Prozess hinterlassen haben muss.
        $cryptoKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::CRYPTO_ENV_KEY] = $cryptoKey;
        $_SERVER[self::CRYPTO_ENV_KEY] = $cryptoKey;
        putenv(self::CRYPTO_ENV_KEY . '=' . $cryptoKey);

        $this->crypto = new MailCredentialCryptoService();
        $this->ssoTokenService = new WebmailSsoTokenService();

        $this->controller = new WebmailController(
            new NullLogger(),
            $this->crypto,
            $this->ssoTokenService
        );

        $this->user = User::create([
            'first_name' => 'Webmail',
            'last_name' => 'Tester',
            'email' => 'webmail.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        $_SESSION = [];
        $_SESSION['user_id'] = $this->user->id;
    }

    protected function tearDown(): void
    {
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

        if ($this->hadSsoEnvValue) {
            $_ENV[self::SSO_ENV_KEY] = $this->originalSsoEnvValue;
            $_SERVER[self::SSO_ENV_KEY] = $this->originalSsoEnvValue;
            putenv(self::SSO_ENV_KEY . '=' . $this->originalSsoEnvValue);
        } else {
            unset($_ENV[self::SSO_ENV_KEY], $_SERVER[self::SSO_ENV_KEY]);
            putenv(self::SSO_ENV_KEY);
        }

        if ($this->hadCryptoEnvValue && $this->originalCryptoEnvValue !== null) {
            $_ENV[self::CRYPTO_ENV_KEY] = $this->originalCryptoEnvValue;
            $_SERVER[self::CRYPTO_ENV_KEY] = $this->originalCryptoEnvValue;
            putenv(self::CRYPTO_ENV_KEY . '=' . $this->originalCryptoEnvValue);
        } else {
            unset($_ENV[self::CRYPTO_ENV_KEY], $_SERVER[self::CRYPTO_ENV_KEY]);
            putenv(self::CRYPTO_ENV_KEY);
        }

        parent::tearDown();
    }

    public function testStartWithoutMailAccountRedirectsToProfileWithError(): void
    {
        $request = $this->makeRequest('POST', '/profile/webmail/start');

        $response = $this->controller->start($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
    }

    public function testStartWithDisabledMailAccountRedirectsToProfileWithError(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'webmail.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('irrelevant-password'),
            'imap_enabled' => false,
        ]);

        $request = $this->makeRequest('POST', '/profile/webmail/start');

        $response = $this->controller->start($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
    }

    public function testStartWithEnabledMailAccountRedirectsToSsoEntryPointWithValidToken(): void
    {
        $plaintextPassword = 'S3cr3t-Imap-Pass';
        $imapUsername = 'webmail.tester@example.org';

        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.org',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_username' => $imapUsername,
            'imap_password_enc' => $this->crypto->encrypt($plaintextPassword),
            'imap_enabled' => true,
        ]);

        $beforeTime = time();
        $request = $this->makeRequest('POST', '/profile/webmail/start');

        $response = $this->controller->start($request, $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString('/webmail/?chormanager-sso&token=', $location);

        $tokenPosition = strpos($location, 'token=');
        $this->assertNotFalse($tokenPosition);
        $encodedToken = substr($location, $tokenPosition + strlen('token='));
        $this->assertNotEmpty($encodedToken);
        $token = rawurldecode($encodedToken);

        $key = base64_decode((string) $_ENV[self::SSO_ENV_KEY], true);
        $this->assertIsString($key);

        $payload = $this->decryptToken($token, $key);

        $this->assertSame($imapUsername, $payload['email']);
        $this->assertSame($imapUsername, $payload['imap_user']);
        $this->assertSame($imapUsername, $payload['smtp_user']);
        $this->assertSame($plaintextPassword, $payload['password']);
        $this->assertSame('smtp.example.org', $payload['smtp_host']);
        $this->assertSame(587, $payload['smtp_port']);
        $this->assertSame('tls', $payload['smtp_enc']);
        $this->assertGreaterThanOrEqual($beforeTime, $payload['exp']);
        $this->assertLessThanOrEqual(time() + 60, $payload['exp']);
        $this->assertNotEmpty($payload['jti']);
    }

    public function testStartArmsTheMailBadgeRefreshSignal(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'webmail.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('S3cr3t-Imap-Pass'),
            'imap_enabled' => true,
        ]);

        $request = $this->makeRequest('POST', '/profile/webmail/start');
        $this->controller->start($request, $this->makeResponse());

        // Das Postfach öffnet sich in einem eigenen Tab; dieser Tab fragt von sich aus
        // nichts mehr nach. Ohne diesen Vermerk sperrte die Wartezeit von fünf Minuten
        // den nächsten IMAP-Abgleich, und der Zähler zeigte weiter die gelesenen Mails.
        $this->assertTrue($_SESSION[MailBadgeController::FORCE_SESSION_KEY] ?? false);
    }

    public function testFailedStartDoesNotArmTheMailBadgeRefreshSignal(): void
    {
        $request = $this->makeRequest('POST', '/profile/webmail/start');
        $this->controller->start($request, $this->makeResponse());

        $this->assertArrayNotHasKey(MailBadgeController::FORCE_SESSION_KEY, $_SESSION);
    }

    public function testStartWithoutSmtpConfigurationOmitsSmtpHostFromToken(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'webmail.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('S3cr3t-Imap-Pass'),
            'imap_enabled' => true,
        ]);

        $request = $this->makeRequest('POST', '/profile/webmail/start');
        $response = $this->controller->start($request, $this->makeResponse());

        $location = $response->getHeaderLine('Location');
        $encodedToken = substr($location, strpos($location, 'token=') + strlen('token='));
        $token = rawurldecode($encodedToken);

        $key = base64_decode((string) $_ENV[self::SSO_ENV_KEY], true);
        $payload = $this->decryptToken($token, $key);

        $this->assertSame('', $payload['smtp_host']);
        $this->assertSame(0, $payload['smtp_port']);
        $this->assertSame('', $payload['smtp_enc']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptToken(string $encoded, string $key): array
    {
        $raw = base64_decode($encoded, true);
        $this->assertIsString($raw);

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        $this->assertIsString($plaintext);

        $decoded = json_decode($plaintext, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
