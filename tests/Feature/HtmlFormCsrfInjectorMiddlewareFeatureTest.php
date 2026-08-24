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

    public function testInjectsIntoResponsesWithoutContentTypeBecauseTwigSetsNone(): void
    {
        $_SESSION = [];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Genau so kommt eine gerenderte Seite an: Slim\Views\Twig::render()
                // schreibt nur in den Rumpf und setzt keinen Content-Type.
                $response = new Response();
                $response->getBody()->write('<form action="/profile" method="post"><button>Save</button></form>');
                return $response;
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertStringContainsString('name="_csrf"', (string) $response->getBody());
    }

    public function testSkipsDownloadsAnnouncedByContentDisposition(): void
    {
        $_SESSION = [];

        $middleware = new HtmlFormCsrfInjectorMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write('<form method="post">Bericht</form>');
                return $response->withHeader('Content-Disposition', 'attachment; filename="report.html"');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertStringNotContainsString('name="_csrf"', (string) $response->getBody());
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
}
