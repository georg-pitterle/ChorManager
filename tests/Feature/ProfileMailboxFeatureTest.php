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

final class ProfileMailboxFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private ProfileController $controller;
    private MailCredentialCryptoService $crypto;
    private User $user;
    private Twig $twigMock;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->crypto = new MailCredentialCryptoService();

        $this->twigMock = $this->createStub(Twig::class);
        $this->controller = new ProfileController(
            $this->twigMock,
            new UserQuery(),
            new PasswordPolicyService(),
            new NullLogger(),
            $this->crypto
        );

        $this->user = User::create([
            'first_name' => 'Mailbox',
            'last_name' => 'Tester',
            'email' => 'mailbox.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $_SESSION = [];
        $_SESSION['user_id'] = $this->user->id;
    }

    protected function tearDown(): void
    {
        UserMailAccount::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();

        parent::tearDown();
    }

    public function testValidMailboxSubmissionCreatesAccountWithEncryptedPassword(): void
    {
        $plaintextPassword = 'S3cr3t-Imap-Pass';

        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => $plaintextPassword,
            'imap_enabled' => '1',
            'mail_badge_enabled' => '1',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertSame('Mailbox-Einstellungen wurden gespeichert.', $_SESSION['success'] ?? null);

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('imap.example.org', $account->imap_host);
        $this->assertSame(993, $account->imap_port);
        $this->assertSame('ssl', $account->imap_encryption);
        $this->assertSame('mailbox.tester@example.org', $account->imap_username);
        $this->assertTrue((bool)$account->imap_enabled);
        $this->assertTrue((bool)$account->mail_badge_enabled);

        $this->assertNotSame($plaintextPassword, $account->imap_password_enc);
        $this->assertSame($plaintextPassword, $this->crypto->decrypt($account->imap_password_enc));
    }

    public function testSmtpFieldsAreSavedWhenProvided(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'whatever-password',
            'smtp_host' => 'smtp.example.org',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('smtp.example.org', $account->smtp_host);
        $this->assertSame(587, $account->smtp_port);
        $this->assertSame('tls', $account->smtp_encryption);
    }

    public function testSmtpFieldsAreNullWhenSmtpHostIsBlank(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'whatever-password',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertNull($account->smtp_host);
        $this->assertNull($account->smtp_port);
        $this->assertNull($account->smtp_encryption);
    }

    public function testInvalidSmtpEncryptionWithSmtpHostIsRejectedAsNull(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'whatever-password',
            'smtp_host' => 'smtp.example.org',
            'smtp_port' => '587',
            'smtp_encryption' => 'bogus',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('smtp.example.org', $account->smtp_host);
        $this->assertNull($account->smtp_encryption);
    }

    public function testControlCharsInUsernameAreRejectedToPreventImapInjection(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => "user\r\nA2 LOGOUT",
            'imap_password' => 'whatever-password',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testControlCharsInPasswordAreRejected(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => "secret\r\ninjected",
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testConnectionTestToPrivateHostIsBlockedWithGenericError(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox/test', [
            'imap_host' => '127.0.0.1',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
        ]);

        $response = $this->controller->testMailboxConnection($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        // Generic message: must not leak whether the internal host:port is
        // open/closed/filtered (SSRF oracle).
        $this->assertSame('Verbindung fehlgeschlagen: Host ist nicht erreichbar.', $_SESSION['error'] ?? null);
    }

    public function testMissingRequiredFieldSetsErrorAndDoesNotCreateAccount(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => '',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'whatever-password',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
        $this->assertArrayNotHasKey('success', $_SESSION);

        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testResubmissionWithBlankPasswordKeepsExistingEncryptedPassword(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'initial-password',
        ]);
        $this->controller->updateMailbox($request, $this->makeResponse());

        $existing = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($existing);
        $originalEncrypted = $existing->imap_password_enc;

        $secondRequest = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '993',
            'imap_encryption' => 'tls',
            'imap_username' => 'mailbox.tester-changed@example.org',
            'imap_password' => '',
        ]);
        $response = $this->controller->updateMailbox($secondRequest, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertSame('Mailbox-Einstellungen wurden gespeichert.', $_SESSION['success'] ?? null);

        $updated = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($updated);
        $this->assertSame('tls', $updated->imap_encryption);
        $this->assertSame('mailbox.tester-changed@example.org', $updated->imap_username);
        $this->assertSame($originalEncrypted, $updated->imap_password_enc);
    }

    public function testPortOutOfRangeIsRejectedWithCleanErrorRedirect(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '99999',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'whatever-password',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testPortZeroIsRejectedWithCleanErrorRedirect(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '0',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'whatever-password',
        ]);

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testTestMailboxConnectionMissingHostRedirectsWithError(): void
    {
        $request = $this->makeRequest('POST', '/profile/mailbox/test', [
            'imap_host' => '',
            'imap_port' => '993',
            'imap_encryption' => 'ssl',
        ]);

        $response = $this->controller->testMailboxConnection($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertNotEmpty($_SESSION['error'] ?? null);
    }

    public function testFailedConnectionTestPrefillsFormOnNextIndexRender(): void
    {
        $testRequest = $this->makeRequest('POST', '/profile/mailbox/test', [
            'imap_host' => 'imap.example.org',
            'imap_port' => '99999',
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password' => 'should-not-be-echoed-back',
            'smtp_host' => 'smtp.example.org',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
        ]);
        $this->controller->testMailboxConnection($testRequest, $this->makeResponse());
        $this->assertNotEmpty($_SESSION['error'] ?? null);

        $capturedData = null;
        $this->twigMock->method('render')
            ->willReturnCallback(function ($response, $template, $data) use (&$capturedData) {
                $capturedData = $data;
                return $response;
            });

        $indexRequest = $this->makeRequest('GET', '/profile');
        $this->controller->index($indexRequest, $this->makeResponse());

        $this->assertIsArray($capturedData);
        $this->assertSame('imap.example.org', $capturedData['mail_account']['imap_host']);
        $this->assertSame('99999', $capturedData['mail_account']['imap_port']);
        $this->assertSame('smtp.example.org', $capturedData['mail_account']['smtp_host']);
        $this->assertArrayNotHasKey('imap_password', $capturedData['mail_account']);
        $this->assertFalse($capturedData['has_saved_account']);
        $this->assertFalse($capturedData['webmail_available']);
        $this->assertArrayNotHasKey('mailbox_form_old', $_SESSION);
    }

    public function testConnectionTestWithJsonAcceptReturnsJsonOnValidationError(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox/test',
            ['imap_host' => '', 'imap_port' => '993', 'imap_encryption' => 'ssl'],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->testMailboxConnection($request, $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertNotEmpty($body['message']);
        $this->assertArrayNotHasKey('mailbox_form_old', $_SESSION);
    }

    public function testConnectionTestWithJsonAcceptReturnsJsonOnBlockedHost(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox/test',
            ['imap_host' => '127.0.0.1', 'imap_port' => '993', 'imap_encryption' => 'ssl'],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->testMailboxConnection($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('Verbindung fehlgeschlagen: Host ist nicht erreichbar.', $body['message']);
        $this->assertArrayNotHasKey('mailbox_form_old', $_SESSION);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testUpdateMailboxWithJsonAcceptReturnsJsonOnSuccess(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox',
            [
                'imap_host' => 'imap.example.org',
                'imap_port' => '993',
                'imap_encryption' => 'ssl',
                'imap_username' => 'mailbox.tester@example.org',
                'imap_password' => 'S3cr3t-Imap-Pass',
                'imap_enabled' => '1',
            ],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertSame('Mailbox-Einstellungen wurden gespeichert.', $body['message']);

        $account = UserMailAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('imap.example.org', $account->imap_host);
    }

    public function testUpdateMailboxWithJsonAcceptReturnsJsonOnValidationError(): void
    {
        $request = $this->makeRequest(
            'POST',
            '/profile/mailbox',
            [
                'imap_host' => '',
                'imap_port' => '993',
                'imap_encryption' => 'ssl',
                'imap_username' => 'mailbox.tester@example.org',
                'imap_password' => 'whatever-password',
            ],
            [],
            ['Accept' => 'application/json']
        );

        $response = $this->controller->updateMailbox($request, $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertNotEmpty($body['message']);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testDeleteMailboxRemovesAccountAndSetsSuccessMessage(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('whatever-password'),
            'imap_enabled' => true,
            'external_webmail_url' => 'https://webmail.example.org/',
        ]);

        $request = $this->makeRequest('POST', '/profile/mailbox/delete');
        $response = $this->controller->deleteMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertSame('Mailbox-Zugang wurde entfernt.', $_SESSION['success'] ?? null);
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());
    }

    public function testDeleteMailboxWithoutExistingAccountIsANoOp(): void
    {
        $this->assertNull(UserMailAccount::where('user_id', $this->user->id)->first());

        $request = $this->makeRequest('POST', '/profile/mailbox/delete');
        $response = $this->controller->deleteMailbox($request, $this->makeResponse());

        $this->assertRedirect($response, '/profile');
        $this->assertArrayNotHasKey('success', $_SESSION);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testIndexReflectsEmptyStateAfterMailboxDeletion(): void
    {
        UserMailAccount::create([
            'user_id' => $this->user->id,
            'imap_host' => 'imap.example.org',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mailbox.tester@example.org',
            'imap_password_enc' => $this->crypto->encrypt('whatever-password'),
            'imap_enabled' => true,
        ]);

        $this->controller->deleteMailbox($this->makeRequest('POST', '/profile/mailbox/delete'), $this->makeResponse());
        unset($_SESSION['success'], $_SESSION['error']);

        $capturedData = null;
        $this->twigMock->method('render')
            ->willReturnCallback(function ($response, $template, $data) use (&$capturedData) {
                $capturedData = $data;
                return $response;
            });

        $this->controller->index($this->makeRequest('GET', '/profile'), $this->makeResponse());

        $this->assertIsArray($capturedData);
        $this->assertFalse($capturedData['has_saved_account']);
        $this->assertFalse($capturedData['webmail_available']);
        $this->assertNull($capturedData['mail_account']);
    }
}
