<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
use App\Middleware\AuthMiddleware;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Was die AuthMiddleware umschließt, ist geschützt - ohne Ausnahmen.
 *
 * Am Anfang stand eine Liste, die `/login`, `/setup` und `/` durchwinkte. Diese
 * drei Routen liegen aber außerhalb der geschützten Gruppe, an der die
 * Middleware hängt (Routes.php), der Block lief also nie. Harmlos war er nur
 * solange: Würde die Middleware jemals global registriert, wäre `/` still
 * ungeschützt - und genau das fiele niemandem auf, weil die Liste wie eine
 * bewusste Entscheidung aussah.
 */
final class AuthMiddlewarePathExemptionFeatureTest extends TestCase
{
    private AuthMiddleware $middleware;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        Bootstrap::setupTestDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new AuthMiddleware(
            new UserQuery(new NameFormatterService()),
            new RememberLoginService(),
            new SessionAuthService(new NameFormatterService(), new RequestContext())
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function formerlyExemptPaths(): array
    {
        return [['/login'], ['/setup'], ['/']];
    }

    #[DataProvider('formerlyExemptPaths')]
    public function testFormerlyExemptPathsAreProtectedLikeAnyOther(string $path): void
    {
        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(302, $response->getStatusCode(), $path);
        $this->assertStringStartsWith('/login', $response->getHeaderLine('Location'), $path);
    }

    /**
     * Die Weiterleitung darf nicht auf sich selbst zeigen: Ein `redirect=/login`
     * schickte die anmeldende Person nach erfolgreicher Anmeldung zurück auf das
     * Anmeldeformular. Für `/dashboard` galt das schon, weil es das Ziel ohnehin
     * ist; `/login` fehlte, solange der Pfad nie hier ankam.
     */
    public function testLoginPathIsNotUsedAsItsOwnRedirectTarget(): void
    {
        $response = $this->middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/login'),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testMiddlewareCarriesNoPathAllowList(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/AuthMiddleware.php');
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            "\$path === '/login'",
            $source,
            'Eine Pfad-Ausnahme in der AuthMiddleware ist eine Lücke, sobald sie global hängt.'
        );
        $this->assertStringNotContainsString("\$path === '/setup'", $source);
    }

    private function handlerThatMustNotRun(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException(
                    'Die AuthMiddleware hat eine unangemeldete Anfrage durchgelassen.'
                );
            }
        };
    }
}
