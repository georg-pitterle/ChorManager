<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Middleware\AuthMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserQuery;
use App\Services\PasswordPolicyService;
use App\Services\RateLimiterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\SafeRedirect;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class LoginRedirectFeatureTest extends TestCase
{
    private AuthMiddleware $middleware;
    private string $rateLimiterStoreDir;
    private User $user;
    private Role $role;
    private string $userPassword = 'Correct-Horse-1';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->role = Role::create([
            'name' => 'Login Redirect Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $this->user = User::create([
            'first_name' => 'Redirect',
            'last_name' => 'Tester',
            'email' => 'redirect.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash($this->userPassword, PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $this->user->roles()->attach($this->role->id);

        $this->rateLimiterStoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chormanager_login_redirect_test_' . bin2hex(random_bytes(4));

        $this->middleware = new AuthMiddleware(
            new UserQuery(),
            new RememberLoginService(),
            new SessionAuthService()
        );

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

    private function makeAuthController(Twig $view): AuthController
    {
        return new AuthController(
            $view,
            new UserQuery(),
            new RememberLoginService(),
            new SessionAuthService(),
            new RateLimiterService($this->rateLimiterStoreDir),
            new PasswordPolicyService(),
            new NullLogger()
        );
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new SlimResponse(200);
            }
        };
    }

    public function testValidRelativePathsPass(): void
    {
        $this->assertSame('/registrations/5', SafeRedirect::sanitize('/registrations/5'));
        $this->assertSame('/events?sort=title', SafeRedirect::sanitize('/events?sort=title'));
    }

    public function testMaliciousTargetsAreRejected(): void
    {
        $this->assertNull(SafeRedirect::sanitize(null));
        $this->assertNull(SafeRedirect::sanitize(''));
        $this->assertNull(SafeRedirect::sanitize('//evil.example'));
        $this->assertNull(SafeRedirect::sanitize('https://evil.example/x'));
        $this->assertNull(SafeRedirect::sanitize('http://evil.example'));
        $this->assertNull(SafeRedirect::sanitize('/valid\\..\\backslash'));
        $this->assertNull(SafeRedirect::sanitize('javascript:alert(1)'));
        $this->assertNull(SafeRedirect::sanitize('relative/path'));
        $this->assertNull(SafeRedirect::sanitize("/line\nbreak"));
        $this->assertNull(SafeRedirect::sanitize('/' . str_repeat('a', 600)));
    }

    public function testLoginFormCarriesRedirectField(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/auth/login.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('name="redirect"', $template);
    }

    public function testMiddlewareRedirectsUnauthenticatedGetToLoginWithEncodedRedirectTarget(): void
    {
        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/registrations/5'),
            $this->passThroughHandler()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?redirect=%2Fregistrations%2F5', $response->getHeaderLine('Location'));
    }

    public function testMiddlewareRedirectsUnauthenticatedPostWithoutRedirectParam(): void
    {
        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/registrations/5'),
            $this->passThroughHandler()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testShowLoginExtractsAndSanitizesValidRedirectQueryParamBeforeRenderingView(): void
    {
        $capturedData = null;
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(ResponseInterface::class),
                'auth/login.twig',
                $this->callback(function (array $data) use (&$capturedData) {
                    $capturedData = $data;
                    return true;
                })
            )
            ->willReturn(new SlimResponse(200));

        $controller = $this->makeAuthController($twig);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/login')
            ->withQueryParams(['redirect' => '/registrations/5']);

        $controller->showLogin($request, new SlimResponse());

        $this->assertSame('/registrations/5', $capturedData['redirect']);
    }

    public function testShowLoginSanitizesMaliciousRedirectQueryParamToNullBeforeRenderingView(): void
    {
        $capturedData = null;
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function ($response, string $template, array $data) use (&$capturedData) {
                $capturedData = $data;
                return new SlimResponse(200);
            });

        $controller = $this->makeAuthController($twig);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/login')
            ->withQueryParams(['redirect' => 'https://evil.example']);

        $controller->showLogin($request, new SlimResponse());

        $this->assertNull($capturedData['redirect']);
    }

    public function testProcessLoginSuccessRedirectsToSanitizedRedirectTarget(): void
    {
        $controller = $this->makeAuthController($this->createStub(Twig::class));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login')
            ->withParsedBody([
                'email' => $this->user->email,
                'password' => $this->userPassword,
                'redirect' => '/registrations/5',
            ]);

        $response = $controller->processLogin($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/registrations/5', $response->getHeaderLine('Location'));
    }

    public function testProcessLoginSuccessWithMaliciousRedirectFallsBackToDashboard(): void
    {
        $controller = $this->makeAuthController($this->createStub(Twig::class));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login')
            ->withParsedBody([
                'email' => $this->user->email,
                'password' => $this->userPassword,
                'redirect' => 'https://evil.example',
            ]);

        $response = $controller->processLogin($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeaderLine('Location'));
    }

    public function testProcessLoginFailurePreservesRedirectTargetForRetry(): void
    {
        $controller = $this->makeAuthController($this->createStub(Twig::class));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login')
            ->withParsedBody([
                'email' => $this->user->email,
                'password' => 'wrong-password',
                'redirect' => '/registrations/5',
            ]);

        $response = $controller->processLogin($request, new SlimResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?redirect=%2Fregistrations%2F5', $response->getHeaderLine('Location'));
    }
}
