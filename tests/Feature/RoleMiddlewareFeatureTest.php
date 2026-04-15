<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RoleMiddlewareFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    public function testFinanceReadOnlyUserCanPassFinanceReadGate(): void
    {
        $_SESSION['can_read_finances'] = true;
        $_SESSION['can_manage_finances'] = false;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/finances'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserWithoutFinanceReadRightGetsForbiddenOnFinanceReadGate(): void
    {
        $_SESSION['can_read_finances'] = false;
        $_SESSION['can_manage_finances'] = false;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/finances'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testFinanceReadOnlyUserGetsForbiddenOnFinanceWriteGate(): void
    {
        $_SESSION['can_read_finances'] = true;
        $_SESSION['can_manage_finances'] = false;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(false, 0, false, false, true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/finances/save'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
