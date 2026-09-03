<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Das PDF im Vorschau-Modal steckt in einem iframe auf die eigene
 * Vorschau-Route. Ohne Ausnahme vom Framing-Verbot bliebe der Rahmen leer.
 *
 * Die Ausnahme ist eng gefasst: nur diese eine Route, und ausdrücklich nicht
 * die Download-Route daneben. Ein zu weites Muster wäre ein Clickjacking-Loch
 * für alles, was unter /attachments liegt.
 */
final class AttachmentPreviewCspFeatureTest extends TestCase
{
    private function headersFor(string $path): ResponseInterface
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);

        return $middleware->process($request, new class () implements RequestHandlerInterface {
            public function handle(Request $request): ResponseInterface
            {
                return new Response();
            }
        });
    }

    public function testPreviewRouteAllowsSameOriginFraming(): void
    {
        $response = $this->headersFor('/attachments/42/preview');

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testDownloadRouteStaysUnframeable(): void
    {
        $response = $this->headersFor('/attachments/42/download');

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testNeighbouringPathsStayUnframeable(): void
    {
        foreach (['/attachments/42/preview/extra', '/attachments/preview', '/dashboard'] as $path) {
            $response = $this->headersFor($path);

            $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $path);
            $this->assertStringContainsString(
                "frame-ancestors 'none'",
                $response->getHeaderLine('Content-Security-Policy'),
                $path
            );
        }
    }

    public function testNewsletterPreviewFrameKeepsItsException(): void
    {
        $response = $this->headersFor('/newsletters/7/preview-frame');

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }
}
