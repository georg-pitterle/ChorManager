<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\AuthMiddleware;
use App\Queries\UserQuery;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Sichert ab, dass das Aufräumen abgelaufener Remember-Me-Token nur dort läuft,
 * wo Remember-Me überhaupt ausgewertet wird - und nicht als Löschabfrage bei
 * jedem Aufruf einer geschützten Route.
 */
final class AuthMiddlewareRememberTokenCleanupFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        unset($_COOKIE[RememberLoginService::COOKIE_NAME]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE[RememberLoginService::COOKIE_NAME]);

        parent::tearDown();
    }

    public function testPurgesExpiredTokensForAnUnauthenticatedRequest(): void
    {
        $rememberLoginService = $this->createMock(RememberLoginService::class);
        $rememberLoginService->expects($this->once())->method('clearExpiredTokens');

        $middleware = new AuthMiddleware(
            $this->createMock(UserQuery::class),
            $rememberLoginService,
            $this->createMock(SessionAuthService::class)
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
            $this->passThroughHandler()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testDoesNotPurgeExpiredTokensForAnAlreadyAuthenticatedSession(): void
    {
        $_SESSION['user_id'] = 42;

        $rememberLoginService = $this->createMock(RememberLoginService::class);
        $rememberLoginService->expects($this->never())->method('clearExpiredTokens');

        $middleware = new AuthMiddleware(
            $this->createMock(UserQuery::class),
            $rememberLoginService,
            $this->createMock(SessionAuthService::class)
        );

        try {
            $middleware->process(
                (new ServerRequestFactory())->createServerRequest('GET', '/dashboard'),
                $this->passThroughHandler()
            );
        } catch (\Throwable) {
            // Der weitere Ablauf liest Einstellungen aus der Datenbank, die in diesem
            // reinen Middleware-Test nicht angebunden ist. Geprüft wird hier
            // ausschließlich, dass die Aufräumabfrage vorher nicht mehr ausgeführt wird.
        }

        $this->addToAssertionCount(1);
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
