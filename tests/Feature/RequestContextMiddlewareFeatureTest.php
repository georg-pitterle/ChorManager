<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Middleware\RequestContextMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

final class RequestContextMiddlewareFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        parent::tearDown();
    }

    /**
     * Reproduces the bug behind a trusted reverse proxy: without ClientIpResolver,
     * the middleware stamped the proxy's REMOTE_ADDR into extra.ip on every log
     * record - including auth.login.failed and authz.denied - instead of the real
     * client address the X-Forwarded-For header carries.
     */
    public function testResolvesClientIpThroughTrustedProxy(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $context = new RequestContext();
        $middleware = new RequestContextMiddleware($context);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/dashboard', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.10, 10.0.0.10');

        $middleware->process($request, $this->passthroughHandler());

        $this->assertSame('203.0.113.10', $context->all()['ip']);
    }

    public function testAnonymousRequestFillsContextWithoutUserId(): void
    {
        $context = new RequestContext();
        $middleware = new RequestContextMiddleware($context);

        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/dashboard',
            ['REMOTE_ADDR' => '203.0.113.9']
        );

        $result = $middleware->process($request, $this->passthroughHandler());

        $this->assertSame(204, $result->getStatusCode());

        $data = $context->all();
        $this->assertSame('GET', $data['method']);
        $this->assertSame('/dashboard', $data['path']);
        $this->assertSame('203.0.113.9', $data['ip']);
        $this->assertArrayHasKey('request_id', $data);
        $this->assertNotSame('', $data['request_id']);
        $this->assertArrayNotHasKey('user_id', $data);
    }

    public function testAuthenticatedRequestCapturesUserIdFromSession(): void
    {
        // Reproduces the real timing bug: a previous request already authenticated
        // and persisted the session to storage, then closed it (end of that
        // request). This request only knows the session id (e.g. via cookie);
        // nothing has read the session data back into $_SESSION yet, which is
        // exactly the state RequestContextMiddleware sees since it runs first in
        // the middleware stack (src/Middleware.php).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_start();
        $sessionId = session_id();
        $_SESSION['user_id'] = 42;
        session_write_close();

        $_SESSION = [];
        session_id($sessionId);

        $context = new RequestContext();
        $middleware = new RequestContextMiddleware($context);

        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/dashboard',
            ['REMOTE_ADDR' => '203.0.113.9']
        );

        $middleware->process($request, $this->passthroughHandler());

        $this->assertSame(42, $context->all()['user_id']);

        session_write_close();
    }

    public function testRequestIdIsPresentAndDiffersBetweenRequests(): void
    {
        $contextOne = new RequestContext();
        (new RequestContextMiddleware($contextOne))->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->passthroughHandler()
        );

        $contextTwo = new RequestContext();
        (new RequestContextMiddleware($contextTwo))->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->passthroughHandler()
        );

        $requestIdOne = $contextOne->all()['request_id'];
        $requestIdTwo = $contextTwo->all()['request_id'];

        $this->assertNotSame('', $requestIdOne);
        $this->assertNotSame($requestIdOne, $requestIdTwo);
    }

    public function testAuthenticationViaSessionAuthServiceUpdatesTheSameContext(): void
    {
        Bootstrap::setupTestDatabase();

        $role = Role::create([
            'name' => 'Context Tester ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);

        $user = User::create([
            'first_name' => 'Context',
            'last_name' => 'Tester',
            'email' => 'context.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        // Simulates the remember-me case: the request-scoped context is filled
        // before the session has an authenticated user (see
        // RequestContextMiddleware, which runs first in the middleware stack).
        $context = new RequestContext();
        (new RequestContextMiddleware($context))->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->passthroughHandler()
        );

        $this->assertArrayNotHasKey('user_id', $context->all());

        (new SessionAuthService(new NameFormatterService(), $context))->setAuthenticatedUser($user);

        $this->assertSame((int) $user->id, $context->all()['user_id']);

        $user->delete();
        $role->delete();
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(204);
            }
        };
    }
}
