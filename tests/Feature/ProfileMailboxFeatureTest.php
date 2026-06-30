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

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->crypto = new MailCredentialCryptoService();

        $twig = $this->createMock(Twig::class);
        $this->controller = new ProfileController(
            $twig,
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
}
