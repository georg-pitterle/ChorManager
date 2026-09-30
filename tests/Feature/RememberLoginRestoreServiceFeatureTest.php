<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RememberLogin;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\RememberLoginRestoreService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Logging\RequestContext;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Unit\Bootstrap;

/**
 * Der Anmeldeweg über das Remember-Me-Cookie steht seit diesem Lauf nur noch
 * einmal im Code. Vorher hatten ihn `AuthMiddleware::process()` und
 * `AuthController::tryAutoLoginFromRememberCookie()` je für sich abgeschrieben,
 * mit Unterschieden, die niemand beabsichtigt hatte: Nur die Middleware räumte
 * abgelaufene Token ab, und nur der Controller vergaß das nicht mehr gültige
 * Anwesenheitsziel.
 */
final class RememberLoginRestoreServiceFeatureTest extends TestCase
{
    private User $user;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->role = Role::create([
            'name' => 'Remember Restore Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Wieder',
            'last_name' => 'Hergestellt',
            'email' => 'remember.restore.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('Correct-Horse-3'),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        RememberLogin::query()->where('user_id', $this->user->id)->delete();
        $this->user->delete();
        $this->role->delete();

        $_SESSION = [];
        $_COOKIE = [];

        parent::tearDown();
    }

    public function testValidCookieEstablishesTheSessionAndRotatesTheToken(): void
    {
        $cookieValue = $this->issueCookie();
        $originalSelector = explode(':', $cookieValue)[0];

        $restored = $this->service()->restoreFromCookie($this->request());

        $this->assertTrue($restored);
        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null);
        $this->assertNull(RememberLogin::where('selector', $originalSelector)->first());
        $this->assertSame(1, RememberLogin::where('user_id', $this->user->id)->count());
    }

    public function testMissingCookieDoesNothing(): void
    {
        $restored = $this->service()->restoreFromCookie($this->request());

        $this->assertFalse($restored);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testUnknownCookieLeavesTheSessionAnonymous(): void
    {
        $_COOKIE[RememberLoginService::COOKIE_NAME] = str_repeat('a', 18) . ':' . str_repeat('b', 64);

        $restored = $this->service()->restoreFromCookie($this->request());

        $this->assertFalse($restored);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testDeactivatedMemberGetsTheTokenDeleted(): void
    {
        $cookieValue = $this->issueCookie();
        $selector = explode(':', $cookieValue)[0];

        $this->user->is_active = 0;
        $this->user->save();

        $restored = $this->service()->restoreFromCookie($this->request());

        $this->assertFalse($restored);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertNull(RememberLogin::where('selector', $selector)->first());
    }

    /**
     * Abgelaufene Token werden dort abgeräumt, wo Remember-Me ausgewertet wird -
     * und nur dann, wenn überhaupt ein Cookie vorliegt. Ohne Cookie darf die
     * Löschabfrage nicht laufen, sonst zahlte sie jeder Suchroboter mit.
     */
    public function testExpiredTokensAreClearedOnlyWhenACookieIsPresent(): void
    {
        $expired = RememberLogin::create([
            'user_id' => (int) $this->user->id,
            'selector' => bin2hex(random_bytes(9)),
            'token_hash' => PasswordHasher::hash(bin2hex(random_bytes(32))),
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
            'created_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        $this->service()->restoreFromCookie($this->request());
        $this->assertNotNull(RememberLogin::find($expired->id), 'Ohne Cookie wird nicht aufgeräumt.');

        $this->issueCookie();
        $this->service()->restoreFromCookie($this->request());

        $this->assertNull(RememberLogin::find($expired->id));
    }

    /**
     * Der Wächter gegen den Rückfall: Sobald eine der beiden Stellen den Ablauf
     * wieder selbst ausschreibt, taucht `rotateToken` dort erneut auf.
     */
    public function testNeitherCallSiteReimplementsTheRestoreFlow(): void
    {
        foreach (
            [
            'src/Middleware/AuthMiddleware.php',
            'src/Controllers/AuthController.php',
            ] as $relativePath
        ) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

            $this->assertStringNotContainsString('->rotateToken(', $source, $relativePath);
            $this->assertStringContainsString('restoreFromCookie(', $source, $relativePath);
        }
    }

    private function issueCookie(): string
    {
        $cookieValue = (new RememberLoginService())->issueForUser((int) $this->user->id, $this->request());
        $_COOKIE[RememberLoginService::COOKIE_NAME] = $cookieValue;

        return $cookieValue;
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dashboard');
    }

    private function service(): RememberLoginRestoreService
    {
        return new RememberLoginRestoreService(
            new RememberLoginService(),
            new UserQuery(new NameFormatterService()),
            new SessionAuthService(new NameFormatterService(), new RequestContext())
        );
    }
}
