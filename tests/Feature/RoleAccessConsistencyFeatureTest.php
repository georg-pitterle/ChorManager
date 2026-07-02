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

/**
 * Guards the consistency of the role/permission wiring so that no route ends up
 * behind the wrong permission gate again (see the finance-read regression that
 * was caused by fragile positional RoleMiddleware arguments).
 */
final class RoleAccessConsistencyFeatureTest extends TestCase
{
    private function routes(): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        return $content;
    }

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    public function testAllRoleMiddlewareInstantiationsUseNamedArguments(): void
    {
        $routes = $this->routes();

        // Positional booleans are what caused the finance-read gate to silently
        // check the mail-queue flag instead. Every gate must be named.
        $this->assertDoesNotMatchRegularExpression('/new RoleMiddleware\(\s*(?:true|false)/', $routes);

        $this->assertGreaterThan(
            0,
            preg_match_all('/new RoleMiddleware\([a-zA-Z]/', $routes),
            'Expected named-argument RoleMiddleware instantiations.'
        );
    }

    public function testFinanceReadRoutesAreGuardedByFinanceReadPermission(): void
    {
        $routes = $this->routes();

        $this->assertStringContainsString('new RoleMiddleware(requiresFinanceRead: true)', $routes);
    }

    public function testRoleManagementIsBoundToUserManagementPermission(): void
    {
        $routes = $this->routes();

        $this->assertMatchesRegularExpression(
            "/\\\$group->group\(\s*'\/roles',.*?new RoleMiddleware\(requiresUserManagement: true\)/s",
            $routes,
            'Role management must be restricted to user administrators (can_manage_users).'
        );
    }

    public function testAdminNavHidesRoleLinkBehindUserManagementPermission(): void
    {
        $nav = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/admin.twig');
        $this->assertIsString($nav);

        $this->assertMatchesRegularExpression(
            '/\{% if session\.can_manage_users %\}.*?href="\/roles"/s',
            $nav
        );
    }

    public function testMailQueueManagerHasNoFinanceReadAccess(): void
    {
        $_SESSION['can_manage_mail_queue'] = true;
        $_SESSION['can_read_finances'] = false;
        $_SESSION['can_manage_finances'] = false;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(requiresFinanceRead: true);
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
}
