<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

class SecurityHeadersMiddlewareFeatureTest extends TestCase
{
    private function cspFor(SecurityHeadersMiddleware $middleware, string $path): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $response = $middleware->process($request, new class () implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });

        return $response->getHeaderLine('Content-Security-Policy');
    }

    /**
     * Die Editor-Seite der Dateiablage schickt das Zugangstoken per Formular in einen
     * Collabora-Rahmen. Nur dort und nur für genau diesen Ursprung öffnet sich die CSP.
     */
    public function testOfficeEditorPageMayFrameAndPostToOfficeServerOnly(): void
    {
        $middleware = new SecurityHeadersMiddleware('https://office.example.test');

        $csp = $this->cspFor($middleware, '/files/12/edit');
        $this->assertStringContainsString('frame-src https://office.example.test', $csp);
        $this->assertStringContainsString("form-action 'self' https://office.example.test", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        foreach (['/files/12', '/files/12/edit/x', '/files/folders/3', '/dashboard'] as $path) {
            $this->assertStringNotContainsString('office.example.test', $this->cspFor($middleware, $path), $path);
        }
        $this->assertStringNotContainsString('frame-src', $this->cspFor(new SecurityHeadersMiddleware(), '/files/12/edit'));
    }

    public function testAddsSecurityHeadersToResponse(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dashboard');

        $response = $middleware->process($request, new class() implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    /**
     * Einzige Ausnahme vom vollständigen Framing-Verbot: die Route, die das fertige Mail-HTML
     * eines gespeicherten Newsletters ausliefert. Sie dient als Quelle des eingebetteten Rahmens
     * auf templates/newsletters/preview.twig und muss deshalb in ein Frame der eigenen
     * Anwendung eingebettet werden dürfen.
     */
    public function testAllowsSameOriginFramingOnlyForNewsletterPreviewFrame(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/newsletters/7/preview-frame'
        );

        $response = $middleware->process($request, new class() implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    /**
     * Regressionsschutz: Eine benachbarte, aber nicht identische Route bleibt vollständig
     * uneingebettet - die Ausnahme darf nicht über ein zu weites Muster streuen.
     */
    public function testDoesNotRelaxFramingForUnrelatedNewsletterRoutes(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/newsletters/7/preview'
        );

        $response = $middleware->process($request, new class() implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testMarksResponsesWithoutOwnCachePolicyAsNoStore(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/users');

        $response = $middleware->process($request, new class() implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });

        $this->assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    public function testKeepsCachePolicyDeclaredByTheRoute(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/help/attachment.png');

        $response = $middleware->process($request, new class() implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return (new Response())->withHeader('Cache-Control', 'public, max-age=86400');
            }
        });

        // Wer etwas ausliefert, das zwischengespeichert werden darf, entscheidet das
        // selbst - die Middleware überschreibt diese Entscheidung nicht.
        $this->assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Pragma'));
    }
}
