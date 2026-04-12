<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\CsrfMiddleware;
use App\Util\Csrf;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CsrfMiddlewareFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGetRequestPassesWithoutCsrfValidation(): void
    {
        $middleware = new CsrfMiddleware();
        $request = $this->makeRequest('GET', '/dashboard');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(204);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(204, $result->getStatusCode());
        $this->assertArrayHasKey(Csrf::SESSION_KEY, $_SESSION);
    }

    public function testPostRequestWithValidTokenPasses(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $request = $this->makeRequest('POST', '/profile', [
            '_csrf' => $_SESSION[Csrf::SESSION_KEY],
        ]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testPostRequestWithInvalidTokenReturns403(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $request = $this->makeRequest('POST', '/profile', [
            '_csrf' => 'invalid-token',
        ]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }
}
