<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Logging\RequestContextProcessor;
use App\Middleware\RoleMiddleware;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Deckt die restlichen Audit-Log-Events ab: abgewiesene Zugriffe (authz.denied)
 * an der einen gemeinsamen Pruefstelle in RoleMiddleware sowie die uebrigen
 * Ereignisse aus dem Spec, die bislang fehlten.
 */
final class AuthorizationLoggingTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testDeniedAccessIsLogged(): void
    {
        // Benutzer ohne can_manage_roles ruft /roles auf.
        // Erwartet: ein Record mit event=authz.denied, der das fehlende Recht
        // nennt. Die Route wird nicht im context wiederholt (context['route']
        // waere byte-identisch mit extra['path']), sondern kommt wie bei jedem
        // anderen Event ueber den RequestContextProcessor.
        [$logger, $handler] = $this->logger();
        $response = $this->callRouteWithoutPermission($logger, '/roles', requiresRoleManagement: true);

        $record = $this->recordFor($handler, 'authz.denied');

        $this->assertNotNull($record);
        $this->assertSame('can_manage_roles', $record->context['permission']);
        $this->assertArrayNotHasKey('route', $record->context);
        $this->assertSame('/roles', $record->extra['path']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAllowedAccessIsNotLoggedAsDenied(): void
    {
        $_SESSION['can_manage_roles'] = true;
        [$logger, $handler] = $this->logger();
        $middleware = new RoleMiddleware(requiresRoleManagement: true, logger: $logger);

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/roles'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertNull($this->recordFor($handler, 'authz.denied'));
    }

    private function callRouteWithoutPermission(
        Logger $logger,
        string $route,
        bool $requiresRoleManagement
    ): ResponseInterface {
        // Stellt denselben Kontext her, den die echte Middleware-Kette liefert:
        // RequestContextMiddleware fuellt normalerweise den RequestContext, den
        // der RequestContextProcessor an jeden Record dieses Loggers haengt -
        // genau der Mechanismus, den RoleMiddleware sich fuer extra['path']
        // zunutze macht statt eines eigenen context['route']. Die Middleware
        // selbst wird hier bewusst nicht durchlaufen (sie startet nebenbei die
        // PHP-Session), stattdessen wird der Kontext direkt befuellt.
        $context = new RequestContext();
        $context->assign(['path' => $route]);
        $logger->pushProcessor(new RequestContextProcessor($context));

        $middleware = new RoleMiddleware(requiresRoleManagement: $requiresRoleManagement, logger: $logger);

        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', $route),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );
    }
}
