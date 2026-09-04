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
 * Die Vorschau zeichnet PDFs seit der Umstellung auf pdf.js selbst auf ein
 * Canvas. Der iframe auf die eigene Vorschau-Route ist damit weg - und mit ihm
 * der Grund, warum diese Route vom Framing-Verbot ausgenommen war.
 *
 * Eine Lockerung ohne Nutzer ist ein offenes Scheunentor ohne Scheune: sie
 * schützt nichts und lädt den nächsten Umbau ein, sich darauf zu stützen.
 * Deshalb prüfen diese Tests, dass /attachments/{id}/preview wieder unter dem
 * vollständigen Verbot steht und nur der Newsletter-Rahmen seine Ausnahme
 * behält.
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

    public function testPreviewRouteIsNoLongerFrameable(): void
    {
        $response = $this->headersFor('/attachments/42/preview');

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
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
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    /**
     * pdf.js entpackt JBIG2- und JPEG2000-Bilder über WebAssembly. Ohne
     * 'wasm-unsafe-eval' verweigert der Browser das Übersetzen des Moduls, und
     * genau die eingescannten Belege blieben leer, für die die Vorschau da ist.
     *
     * Die Lockerung erlaubt das Übersetzen von WebAssembly, nicht eval() für
     * JavaScript. Woher die Bytes kommen dürfen, regelt weiterhin
     * default-src/connect-src 'self' - also ausschließlich die eigene Herkunft.
     */
    public function testCspAllowsWebAssemblyButNotScriptEval(): void
    {
        $csp = $this->headersFor('/dashboard')->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'wasm-unsafe-eval'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval';", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }
}
