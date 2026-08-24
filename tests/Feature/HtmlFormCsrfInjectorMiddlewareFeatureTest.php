<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\HtmlFormCsrfInjectorMiddleware;
use App\Util\Csrf;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class HtmlFormCsrfInjectorMiddlewareFeatureTest extends TestCase
{
    use TestHttpHelpers;


    public function testInjectsCsrfIntoHtmlPostFormsWithoutToken(): void
    {
        $_SESSION = [];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write('<form action="/profile" method="post"><button>Save</button></form>');
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $response = $middleware->process($request, $handler);
        $body = (string) $response->getBody();

        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertStringContainsString((string) $_SESSION[Csrf::SESSION_KEY], $body);
    }

    public function testDoesNotInjectIntoGetFormCarryingADataMethodAttribute(): void
    {
        $_SESSION = [];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write(
                    '<form action="/search" method="get" data-method="post"><button>Suchen</button></form>'
                );
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $response = $middleware->process($request, $handler);
        $body = (string) $response->getBody();

        // Ein GET-Formular hängt seine Felder an die URL - ein dort eingefügter Token
        // stünde in Verlauf, Referrer und Zugriffsprotokollen.
        $this->assertStringNotContainsString('name="_csrf"', $body);
    }

    public function testInjectsIntoLargeFormsBeyondThePcreStackLimit(): void
    {
        $_SESSION = [];

        // Grosse Formulare sind in diesem Projekt real: die Rollenverwaltung rendert je
        // Berechtigung einen Kontrollkaestchen-Block in ein einziges Formular und traegt
        // selbst kein `_csrf`-Feld. Ueberschreitet der Formularinhalt die Grenze des
        // PCRE-Stapelspeichers, lieferte der frueher verwendete Ausdruck `null` - die
        // Middleware gab die Antwort unveraendert zurueck und jede Absendung dieses
        // Formulars wurde danach mit 403 abgewiesen.
        $block = '<div class="form-check">'
            . '<input class="form-check-input" type="checkbox" name="can_manage_x" id="x">'
            . '<label class="form-check-label" for="x">Berechtigung</label>'
            . '</div>';
        $largeForm = '<form action="/roles" method="post">'
            . str_repeat($block, 400)
            . '<button>Speichern</button></form>';

        $this->assertGreaterThan(24_576, strlen($largeForm));

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class ($largeForm) implements RequestHandlerInterface {
            public function __construct(private readonly string $markup)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write($this->markup);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $response = $middleware->process($request, $handler);
        $body = (string) $response->getBody();

        $this->assertSame(1, substr_count($body, 'name="_csrf"'));
        $this->assertStringContainsString((string) $_SESSION[Csrf::SESSION_KEY], $body);
    }

    public function testDoesNotDuplicateExistingCsrfField(): void
    {
        $_SESSION = [Csrf::SESSION_KEY => bin2hex(random_bytes(32))];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write(
                    '<form action="/login" method="post"><input type="hidden" name="_csrf" value="abc"></form>'
                );
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $response = $middleware->process($request, $handler);
        $body = (string) $response->getBody();

        $this->assertSame(1, substr_count($body, 'name="_csrf"'));
    }

    public function testKeepsAnExistingContentLengthInSyncWithTheRewrittenBody(): void
    {
        $_SESSION = [];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $markup = '<form action="/profile" method="post"><button>Speichern</button></form>';
                $response = new Response();
                $response->getBody()->write($markup);

                return $response
                    ->withHeader('Content-Type', 'text/html; charset=utf-8')
                    ->withHeader('Content-Length', (string) strlen($markup));
            }
        };

        $response = $middleware->process($request, $handler);
        $body = (string) $response->getBody();

        // Das eingefügte Feld verlängert den Rumpf. Bliebe die alte Längenangabe stehen,
        // schnitte der Browser die Seite genau dort ab.
        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
    }

    public function testLogsWhenTheFormRewriteFails(): void
    {
        $_SESSION = [];

        [$logger, $logHandler] = $this->logger();
        $middleware = new HtmlFormCsrfInjectorMiddleware($logger);
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write('<form action="/profile" method="post"><button>Speichern</button></form>');
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $originalLimit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $response = $middleware->process($request, $handler);
        } finally {
            ini_set('pcre.backtrack_limit', $originalLimit);
        }

        // Scheitert der Ausdruck, geht die Antwort ohne Token hinaus und jede Absendung
        // dieses Formulars endet danach mit 403. Ohne Logzeile ist dieser Ausfall von
        // außen nicht von einem echten Angriff zu unterscheiden.
        $this->assertStringNotContainsString('name="_csrf"', (string) $response->getBody());
        $record = $this->recordFor($logHandler, 'security.csrf.form_injection_failed');
        $this->assertNotNull($record);
        $this->assertSame('Backtrack limit exhausted', $record->context['reason'] ?? null);
    }
}
