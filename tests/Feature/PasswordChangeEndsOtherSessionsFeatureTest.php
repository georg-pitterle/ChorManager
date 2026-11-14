<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Logging\RequestContext;
use App\Middleware\AuthMiddleware;
use App\Models\OidcAccessToken;
use App\Models\PasswordReset;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\NameFormatterService;
use App\Services\Oidc\OidcClientService;
use App\Services\PasswordPolicyService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Passwortwechsel beendet alle anderen Anmeldungen des Kontos.
 *
 * Bisher verwarf er nur die Angemeldet-bleiben-Token. Eine Sitzung, die auf einem
 * fremden Gerät schon lief, blieb angemeldet - ausgerechnet im Ernstfall, für den
 * man das Passwort wechselt. Dasselbe galt für die Zugangstoken angeschlossener
 * Anwendungen (OIDC).
 *
 * Die Sitzung, aus der heraus gewechselt wird, bleibt angemeldet: Wer sein
 * Passwort ändert, soll sich nicht selbst aussperren.
 */
final class PasswordChangeEndsOtherSessionsFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testProfilePasswordChangeEndsOlderSessionsButKeepsTheCurrentOne(): void
    {
        $user = $this->createUser('profil');
        $before = time() - 60;

        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => $before];
        $this->changePasswordInProfile();
        $currentSession = $_SESSION;

        $user->refresh();
        $this->assertNotNull($user->sessions_valid_after, 'Die Grenze des Kontos muss gesetzt sein');

        // Eine zweite Sitzung desselben Kontos, angemeldet vor dem Wechsel.
        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => $before];
        $this->assertRedirectsToLogin($this->runAuthMiddleware());

        // Die Sitzung, aus der gewechselt wurde. Die Middleware hat die vorige eben
        // zerstört; ohne laufende Sitzung überschriebe deren nächster Start das
        // hier gesetzte Array mit dem leeren Inhalt einer neuen.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = $currentSession;
        $this->assertSame(200, $this->runAuthMiddleware()->getStatusCode());
    }

    public function testSessionStartedAfterThePasswordChangeStaysSignedIn(): void
    {
        $user = $this->createUser('neu');
        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];
        $this->changePasswordInProfile();

        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() + 1];

        $this->assertSame(200, $this->runAuthMiddleware()->getStatusCode());
    }

    public function testOtherAccountsKeepTheirSessions(): void
    {
        $user = $this->createUser('wechsler');
        $bystander = $this->createUser('unbeteiligt');

        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];
        $this->changePasswordInProfile();

        $_SESSION = ['user_id' => (int) $bystander->id, 'auth_epoch' => time() - 60];
        $this->assertSame(200, $this->runAuthMiddleware()->getStatusCode());
    }

    public function testFailedPasswordChangeEndsNothing(): void
    {
        $user = $this->createUser('fehlversuch');
        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];

        $this->profileController()->updatePassword(
            $this->makeRequest('POST', '/profile/password', [
                'old_password' => 'Wrong-Password-9',
                'new_password' => 'Correct-Horse-2',
                'new_password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $user->refresh();
        $this->assertNull($user->sessions_valid_after);
    }

    public function testPasswordResetEndsEverySessionOfTheAccount(): void
    {
        $user = $this->createUser('reset');
        $token = bin2hex(random_bytes(32));
        PasswordReset::create([
            'email' => $user->email,
            'token_hash' => PasswordHasher::hash($token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        (new PasswordResetController($this->createStub(Twig::class)))->processReset(
            $this->makeRequest('POST', '/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'Correct-Horse-2',
                'password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];
        $this->assertRedirectsToLogin($this->runAuthMiddleware());
    }

    public function testPasswordChangeRevokesTheTokensOfConnectedApplications(): void
    {
        $user = $this->createUser('oidc');
        $client = (new OidcClientService())->createClient('Nextcloud', ['https://cloud.example/callback'])['client'];
        OidcAccessToken::create([
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'client_id' => (string) $client->client_id,
            'user_id' => (int) $user->id,
            'scope' => 'openid',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION = ['user_id' => (int) $user->id, 'auth_epoch' => time() - 60];
        $this->changePasswordInProfile();

        $this->assertSame(
            0,
            OidcAccessToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count()
        );
    }

    private function changePasswordInProfile(): void
    {
        $this->profileController()->updatePassword(
            $this->makeRequest('POST', '/profile/password', [
                'old_password' => 'Old-Password-1',
                'new_password' => 'Correct-Horse-2',
                'new_password_confirm' => 'Correct-Horse-2',
            ]),
            $this->makeResponse()
        );

        $this->assertSame('Dein Passwort wurde erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }

    private function runAuthMiddleware(): ResponseInterface
    {
        $names = new NameFormatterService();
        $middleware = new AuthMiddleware(
            new UserQuery($names),
            new RememberLoginService(),
            new SessionAuthService($names, new RequestContext())
        );

        return $middleware->process(
            $this->makeRequest('GET', '/dashboard'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );
    }

    private function assertRedirectsToLogin(ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/login', $response->getHeaderLine('Location'));
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
            'first_name' => 'Sitzung',
            'last_name' => ucfirst($prefix),
            'email' => $prefix . '.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('Old-Password-1'),
            'is_active' => 1,
        ]);
    }
}
