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

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, false, true);
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

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, false, true);
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

        $middleware = new RoleMiddleware(false, 0, false, false, true, false, false, false, false, false, false, false, false);
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

    public function testUserWithMailQueuePermissionCanPassMailQueueGate(): void
    {
        $_SESSION['can_manage_mail_queue'] = true;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, true, false);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/mail-queue'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserWithoutMailQueuePermissionGetsForbiddenOnMailQueueGate(): void
    {
        $_SESSION['can_manage_mail_queue'] = false;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(false, 0, false, false, false, false, false, false, false, false, false, true, false);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/mail-queue'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserWithAttendanceAllButNotOwnAttendanceCanPassAttendanceGate(): void
    {
        $_SESSION['can_manage_attendance'] = false;
        $_SESSION['can_manage_attendance_all'] = true;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(requiresAttendanceManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/attendance'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserManagerWithoutMasterDataPermissionGetsForbiddenOnMasterDataGate(): void
    {
        $_SESSION['can_manage_master_data'] = false;
        // can_manage_users must no longer act as an implicit fallback for Stammdaten/Projekte.
        $_SESSION['can_manage_users'] = true;

        $middleware = new RoleMiddleware(requiresMasterDataManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/projects'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserWithNeitherAttendanceRightGetsForbiddenOnAttendanceGateEvenAsUserManager(): void
    {
        $_SESSION['can_manage_attendance'] = false;
        $_SESSION['can_manage_attendance_all'] = false;
        // can_manage_users must no longer act as an implicit fallback for Anwesenheit.
        $_SESSION['can_manage_users'] = true;

        $middleware = new RoleMiddleware(requiresAttendanceManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/attendance'),
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
