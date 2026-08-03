<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Logging\RequestContext;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class AuthLoggingTest extends TestCase
{
    use TestHttpHelpers;

    private string $rateLimiterStoreDir;
    private Role $role;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        User::where('email', 'admin@example.org')->delete();

        $this->role = Role::create([
            'name' => 'Auth Logging Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Example',
            'email' => 'admin@example.org',
            'password' => password_hash('correct-password', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        $this->rateLimiterStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chormanager_auth_logging_test_' . bin2hex(random_bytes(4));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->user->delete();
        $this->role->delete();

        if (is_dir($this->rateLimiterStoreDir)) {
            foreach (glob($this->rateLimiterStoreDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->rateLimiterStoreDir);
        }

        parent::tearDown();
    }

    public function testSuccessfulLoginIsLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'admin@example.org', 'correct-password');

        $this->assertTrue($this->hasEvent($handler, 'auth.login.succeeded'));
    }

    public function testFailedLoginIsLoggedWithReason(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'admin@example.org', 'wrong-password');

        $records = $handler->getRecords();
        $match = array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.login.failed'
        );

        $this->assertNotEmpty($match);
        $this->assertSame('bad_credentials', array_values($match)[0]->context['reason']);
    }

    public function testFailedLoginForUnknownEmailIsLoggedWithUnknownUserReason(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'no-such-account@example.org', 'whatever-password');

        $records = $handler->getRecords();
        $match = array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.login.failed'
        );

        $this->assertNotEmpty($match);
        $this->assertSame('unknown_user', array_values($match)[0]->context['reason']);
    }

    /**
     * A deactivated member's login attempt must not read as account enumeration:
     * UserQuery::findByEmail filters is_active=1, so the lookup also returns null
     * for a deactivated account. Without a dedicated 'inactive' reason, repeated
     * attempts by an archived member would be indistinguishable from a guess
     * against a nonexistent address in the log.
     */
    public function testFailedLoginForInactiveAccountIsLoggedWithInactiveReason(): void
    {
        [$logger, $handler] = $this->logger();

        $inactiveEmail = 'inactive-member@example.org';
        $inactiveUser = User::create([
            'first_name' => 'Inactive',
            'last_name' => 'Member',
            'email' => $inactiveEmail,
            'password' => password_hash('correct-password', PASSWORD_DEFAULT),
            'is_active' => 0,
        ]);

        try {
            $controller = $this->makeAuthController($logger);
            $this->submitLogin($controller, $inactiveEmail, 'correct-password');

            $records = $handler->getRecords();
            $match = array_filter(
                $records,
                static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.login.failed'
            );

            $this->assertNotEmpty($match);
            $this->assertSame('inactive', array_values($match)[0]->context['reason']);
        } finally {
            $inactiveUser->delete();
        }
    }

    public function testPasswordIsNeverLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $this->submitLogin($controller, 'admin@example.org', 'super-secret-value');

        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString('super-secret-value', json_encode($record->context));
        }
    }

    public function testLogoutIsLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/logout');
        $controller->logout($request, new SlimResponse());

        $this->assertTrue($this->hasEvent($handler, 'auth.logout'));
    }

    public function testRateLimitedLoginIsLogged(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        for ($i = 0; $i < 11; $i++) {
            $this->submitLogin($controller, 'admin@example.org', 'wrong-password');
        }

        $this->assertTrue($this->hasEvent($handler, 'auth.login.rate_limited'));
    }

    /**
     * Owner decision: a brute-force signal must survive an operator lowering
     * noise by raising the level to WARNING, so this event is WARNING rather
     * than INFO - consistent with security.csrf.rejected and
     * security.upload.rejected.
     */
    public function testRateLimitedLoginIsLoggedAtWarningLevel(): void
    {
        [$logger, $handler] = $this->logger();

        $controller = $this->makeAuthController($logger);
        for ($i = 0; $i < 11; $i++) {
            $this->submitLogin($controller, 'admin@example.org', 'wrong-password');
        }

        $records = $handler->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'auth.login.rate_limited'
        ));

        $this->assertNotEmpty($match);
        $this->assertSame(\Monolog\Level::Warning, $match[0]->level);
    }

    private function makeAuthController(Logger $logger): AuthController
    {
        return new AuthController(
            $this->createStub(Twig::class),
            new UserQuery(new \App\Services\NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new \App\Services\NameFormatterService(), new RequestContext()),
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            $logger
        );
    }

    private function submitLogin(AuthController $controller, string $email, string $password): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login')
            ->withParsedBody([
                'email' => $email,
                'password' => $password,
            ]);

        return $controller->processLogin($request, new SlimResponse());
    }
}
