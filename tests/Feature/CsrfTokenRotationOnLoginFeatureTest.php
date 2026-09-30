<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Controllers\ProfileController;
use App\Logging\RequestContext;
use App\Middleware\AuthMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\MailCredentialCryptoService;
use App\Services\NameFormatterService;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\Csrf;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der CSRF-Token muss mit der Sitzungskennung wechseln.
 *
 * `session_regenerate_id(true)` tauscht die Kennung, nimmt aber die Sitzungsdaten
 * mit hinüber - der Token in `$_SESSION` überlebte den Wechsel also. Wer ihn vor
 * der Anmeldung kennt, etwa weil er der eigenen Sitzung ein Cookie untergeschoben
 * hat, kennt ihn danach weiter: Die neue Kennung schickt der Browser des Opfers
 * bei einer gefälschten Anfrage selbst mit, und der mitgelieferte Token passt.
 * Damit wäre der CSRF-Schutz für diese Sitzung wirkungslos.
 */
final class CsrfTokenRotationOnLoginFeatureTest extends TestCase
{
    private User $user;
    private Role $role;
    private string $password = 'Correct-Horse-7';
    private string $rateLimiterStoreDir;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->role = Role::create([
            'name' => 'CSRF Rotation Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Rotations',
            'last_name' => 'Prüferin',
            'email' => 'csrf.rotation.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash($this->password),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        $this->rateLimiterStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chormanager_csrf_rotation_test_' . bin2hex(random_bytes(4));

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE = [];

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

    public function testRotateReplacesTheTokenAndKeepsItInTheSession(): void
    {
        $before = Csrf::ensureToken();

        $after = Csrf::rotate();

        $this->assertNotSame($before, $after);
        $this->assertSame($after, $_SESSION[Csrf::SESSION_KEY]);
        $this->assertSame(64, strlen($after));
        $this->assertFalse(Csrf::validate($before));
        $this->assertTrue(Csrf::validate($after));
    }

    public function testSuccessfulLoginRotatesTheToken(): void
    {
        $before = Csrf::ensureToken();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '198.51.100.4'])
            ->withParsedBody([
                'email' => (string) $this->user->email,
                'password' => $this->password,
            ]);

        $response = $this->authController()->processLogin($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null);
        $this->assertNotSame($before, $_SESSION[Csrf::SESSION_KEY] ?? null);
    }

    public function testFailedLoginLeavesTheTokenAlone(): void
    {
        $before = Csrf::ensureToken();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '198.51.100.5'])
            ->withParsedBody([
                'email' => (string) $this->user->email,
                'password' => 'falsches-Passwort',
            ]);

        $this->authController()->processLogin($request, new SlimResponse());

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertSame($before, $_SESSION[Csrf::SESSION_KEY] ?? null);
    }

    public function testRememberMeRestoreInTheAuthMiddlewareRotatesTheToken(): void
    {
        $rememberService = new RememberLoginService();
        $_COOKIE[RememberLoginService::COOKIE_NAME] = $rememberService->issueForUser(
            (int) $this->user->id,
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard')
        );

        $before = Csrf::ensureToken();

        $middleware = new AuthMiddleware(
            new UserQuery(new NameFormatterService()),
            $rememberService,
            new SessionAuthService(new NameFormatterService(), new RequestContext())
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->passthroughHandler()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((int) $this->user->id, $_SESSION['user_id'] ?? null);
        $this->assertNotSame($before, $_SESSION[Csrf::SESSION_KEY] ?? null);
    }

    public function testPasswordChangeRotatesTheToken(): void
    {
        $_SESSION['user_id'] = (int) $this->user->id;
        $before = Csrf::ensureToken();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/profile/password')
            ->withParsedBody([
                'old_password' => $this->password,
                'new_password' => 'Neues-Passwort-9',
                'new_password_confirm' => 'Neues-Passwort-9',
            ]);

        $this->profileController()->updatePassword($request, new SlimResponse());

        $this->assertArrayNotHasKey('error', $_SESSION);
        $this->assertNotSame($before, $_SESSION[Csrf::SESSION_KEY] ?? null);
    }

    /**
     * Der Wächter für die Zukunft: Jede Stelle, die die Sitzungskennung tauscht,
     * muss den Token gleich mitnehmen. Ohne diese Prüfung fiele eine neu
     * hinzugefügte Stelle ohne Erneuerung niemandem auf - der Befund von damals
     * käme still zurück.
     */
    public function testEverySessionRegenerationIsFollowedByATokenRotation(): void
    {
        $missing = [];

        foreach ($this->phpSourceFiles() as $file) {
            $lines = file($file) ?: [];

            foreach ($lines as $index => $line) {
                $code = ltrim($line);
                if (str_starts_with($code, '*') || str_starts_with($code, '//')) {
                    continue;
                }

                if (!str_contains($code, 'session_regenerate_id(')) {
                    continue;
                }

                $window = implode('', array_slice($lines, $index, 4));
                if (!str_contains($window, 'Csrf::rotate()')) {
                    $missing[] = $file . ':' . ($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Nach session_regenerate_id() fehlt Csrf::rotate(): ' . implode(', ', $missing)
        );
    }

    /**
     * @return list<string>
     */
    private function phpSourceFiles(): array
    {
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src');
        $files = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function authController(): AuthController
    {
        return new AuthController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new NameFormatterService(), new RequestContext()),
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            new NullLogger()
        );
    }

    private function profileController(): ProfileController
    {
        return new ProfileController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new PasswordPolicyService(),
            new NullLogger(),
            new MailCredentialCryptoService()
        );
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new SlimResponse(200);
            }
        };
    }
}
