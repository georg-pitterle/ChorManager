<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RoleMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

    protected function tearDown(): void
    {
        // Never leave a test-installed default logger behind: it is static,
        // process-wide state and would otherwise leak into unrelated
        // RoleMiddleware instances built later in the same PHPUnit run.
        RoleMiddleware::setDefaultLogger(null);
    }

    public function testFinanceReadOnlyUserCanPassFinanceReadGate(): void
    {
        $_SESSION['can_read_finances'] = true;
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

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserWithoutFinanceReadRightGetsForbiddenOnFinanceReadGate(): void
    {
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

    public function testFinanceReadOnlyUserGetsForbiddenOnFinanceWriteGate(): void
    {
        $_SESSION['can_read_finances'] = true;
        $_SESSION['can_manage_finances'] = false;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(requiresFinanceManagement: true);
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

    public function testForbiddenResponseDeclaresPlainTextWithUtf8Charset(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_own_voice_group'] = false;

        $middleware = new RoleMiddleware(requiresUserManagement: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/users'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        // Die Meldung enthält Umlaute; ohne Zeichensatzangabe stellt der Browser sie
        // wegen `X-Content-Type-Options: nosniff` als Ersatzzeichen dar.
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('für', (string) $response->getBody());
    }

    public function testUserWithMailQueuePermissionCanPassMailQueueGate(): void
    {
        $_SESSION['can_manage_mail_queue'] = true;
        $_SESSION['can_manage_users'] = false;

        $middleware = new RoleMiddleware(requiresMailQueueManagement: true);
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

        $middleware = new RoleMiddleware(requiresMailQueueManagement: true);
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

    /**
     * Demonstrates the static default-logger bridge (RoleMiddleware is built
     * with `new` at every Routes.php call site, never through the DI
     * container) and that setDefaultLogger(null) actually clears it: once
     * reset, an instance built with no explicit logger falls back to the
     * NullLogger again instead of continuing to use the previously installed
     * one. Without this reset capability, a test-installed logger would leak
     * into every RoleMiddleware built afterward in the same PHPUnit process.
     */
    public function testDefaultLoggerBridgeCanBeResetToNull(): void
    {
        $_SESSION['can_manage_roles'] = false;

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        RoleMiddleware::setDefaultLogger($logger);
        $middlewareUsingDefault = new RoleMiddleware(requiresRoleManagement: true);
        $middlewareUsingDefault->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/roles'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertNotEmpty($handler->getRecords(), 'Default logger should have received the denial.');

        RoleMiddleware::setDefaultLogger(null);
        $handler->clear();

        $middlewareAfterReset = new RoleMiddleware(requiresRoleManagement: true);
        $middlewareAfterReset->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/roles'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertEmpty(
            $handler->getRecords(),
            'After reset, the old handler must no longer receive log records from new instances.'
        );
    }

    /**
     * Die Oberfläche ruft rechtegeschützte Routen per fetch auf und wertet JSON
     * aus. Kam die Abweisung als text/plain zurück, fiel newsletters.js auf ein
     * pauschales "Speichern fehlgeschlagen." zurück - der eigentliche Grund
     * ("keine Berechtigung zur Newsletter-Verwaltung") ging verloren.
     */
    public function testDeniesWithJsonWhenTheCallerAcceptsJson(): void
    {
        $_SESSION['can_manage_newsletters'] = false;

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletters')
            ->withHeader('Accept', 'application/json');

        $middleware = new RoleMiddleware(requiresNewsletterManagement: true);
        $response = $middleware->process($request, $this->passthroughHandler());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($payload);
        // newsletters.js liest `error`, users.js liest `message` - beide Schlüssel
        // tragen denselben Text, damit kein Aufrufer angepasst werden muss.
        $this->assertSame($payload['error'] ?? null, $payload['message'] ?? null);
        $this->assertStringContainsString('Newsletter-Verwaltung', (string) ($payload['error'] ?? ''));
    }

    public function testDeniesWithJsonForXmlHttpRequestWithoutAcceptHeader(): void
    {
        $_SESSION['can_manage_tasks'] = false;

        // newsletters.js schickt nur X-Requested-With, kein Accept.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/1/status')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');

        $middleware = new RoleMiddleware(requiresTaskManagement: true);
        $response = $middleware->process($request, $this->passthroughHandler());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testDeniesWithPlainTextForAnOrdinaryBrowserRequest(): void
    {
        $_SESSION['can_manage_roles'] = false;

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/roles')
            ->withHeader('Accept', 'text/html,application/xhtml+xml');

        $middleware = new RoleMiddleware(requiresRoleManagement: true);
        $response = $middleware->process($request, $this->passthroughHandler());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Rollenverwaltung', (string) $response->getBody());
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
