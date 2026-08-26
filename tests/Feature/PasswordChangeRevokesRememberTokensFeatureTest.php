<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Models\InvitationToken;
use App\Models\PasswordReset;
use App\Models\RememberLogin;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Services\RememberLoginService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Passwortwechsel ist die übliche Reaktion auf einen Verdacht. Bleiben die
 * Angemeldet-bleiben-Token danach gültig, hat er genau die Wirkung nicht, die
 * man von ihm erwartet: Wer das Konto übernommen hat, bleibt angemeldet.
 *
 * Von außen ist nicht erkennbar, ob ein Wechsel freiwillig oder ein Ernstfall
 * ist - deshalb verwerfen alle drei Wege (Reset-Link, Einladung, Profil) die
 * Token des Kontos, und zwar nur die des betroffenen Kontos.
 */
class PasswordChangeRevokesRememberTokensFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    public function testPasswordResetRevokesEveryRememberTokenOfTheAccount(): void
    {
        $user = $this->createUser('reset');
        $other = $this->createUser('bystander');

        $this->issueTokens($user, 2);
        $this->issueTokens($other, 1);

        $token = bin2hex(random_bytes(32));
        PasswordReset::create([
            'email' => $user->email,
            'token' => password_hash($token, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->passwordResetController()->processReset(
            $this->makeRequest('POST', '/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'Correct-Horse-2',
                'password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $this->assertSame(0, $this->tokenCount($user), 'Reset muss alle Token des Kontos verwerfen');
        $this->assertSame(1, $this->tokenCount($other), 'Fremde Konten bleiben unberührt');
    }

    public function testInvitationConsumptionRevokesEveryRememberTokenOfTheAccount(): void
    {
        $user = $this->createUser('invite');
        $other = $this->createUser('invite-bystander');

        $this->issueTokens($user, 2);
        $this->issueTokens($other, 1);

        $token = bin2hex(random_bytes(32));
        InvitationToken::create([
            'user_id' => $user->id,
            'selector' => bin2hex(random_bytes(9)),
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->passwordResetController()->processReset(
            $this->makeRequest('POST', '/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'Correct-Horse-2',
                'password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $this->assertSame(0, $this->tokenCount($user));
        $this->assertSame(1, $this->tokenCount($other));
    }

    public function testProfilePasswordChangeRevokesEveryRememberTokenOfTheAccount(): void
    {
        $user = $this->createUser('profile');
        $other = $this->createUser('profile-bystander');

        $this->issueTokens($user, 2);
        $this->issueTokens($other, 1);

        $_SESSION = ['user_id' => (int) $user->id];

        $this->profileController()->updatePassword(
            $this->makeRequest('POST', '/profile/password', [
                'old_password' => 'Old-Password-1',
                'new_password' => 'Correct-Horse-2',
                'new_password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $this->assertSame('Dein Passwort wurde erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertSame(0, $this->tokenCount($user));
        $this->assertSame(1, $this->tokenCount($other));
    }

    public function testFailedProfilePasswordChangeKeepsTheTokens(): void
    {
        $user = $this->createUser('profile-fail');
        $this->issueTokens($user, 2);

        $_SESSION = ['user_id' => (int) $user->id];

        $this->profileController()->updatePassword(
            $this->makeRequest('POST', '/profile/password', [
                'old_password' => 'Wrong-Password-9',
                'new_password' => 'Correct-Horse-2',
                'new_password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $this->assertSame('Das bisherige Passwort ist falsch.', $_SESSION['error'] ?? null);
        $this->assertSame(2, $this->tokenCount($user), 'Ein abgelehnter Wechsel darf niemanden abmelden');
    }

    public function testInvalidateAllForUserOnlyTouchesTheGivenAccount(): void
    {
        $user = $this->createUser('service');
        $other = $this->createUser('service-bystander');

        $this->issueTokens($user, 3);
        $this->issueTokens($other, 2);

        $revoked = (new RememberLoginService())->invalidateAllForUser((int) $user->id);

        $this->assertSame(3, $revoked);
        $this->assertSame(0, $this->tokenCount($user));
        $this->assertSame(2, $this->tokenCount($other));
    }

    private function passwordResetController(): PasswordResetController
    {
        return new PasswordResetController($this->createStub(Twig::class));
    }

    private function profileController(): ProfileController
    {
        return new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new Logger('test'),
            new MailCredentialCryptoService()
        );
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'first_name' => 'Remember',
            'last_name' => 'Tester',
            'email' => $prefix . '.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => password_hash('Old-Password-1', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    private function issueTokens(User $user, int $count): void
    {
        $service = new RememberLoginService();
        $request = $this->makeRequest('POST', '/login');

        for ($i = 0; $i < $count; $i++) {
            $service->issueForUser((int) $user->id, $request);
        }
    }

    private function tokenCount(User $user): int
    {
        return RememberLogin::where('user_id', (int) $user->id)->count();
    }
}
