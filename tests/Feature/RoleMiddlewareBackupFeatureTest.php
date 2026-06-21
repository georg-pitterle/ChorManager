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

final class RoleMiddlewareBackupFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    public function testUserWithBackupPermissionCanPassBackupGate(): void
    {
        $_SESSION['can_manage_backups'] = true;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(requiresBackupManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/backups'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserWithoutBackupPermissionGetsForbiddenOnBackupGateEvenAsUserManager(): void
    {
        $_SESSION['can_manage_backups'] = false;
        $_SESSION['can_manage_users'] = true;

        $middleware = new RoleMiddleware(requiresBackupManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/backups'),
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
