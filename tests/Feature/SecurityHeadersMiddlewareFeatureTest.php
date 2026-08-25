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
}
