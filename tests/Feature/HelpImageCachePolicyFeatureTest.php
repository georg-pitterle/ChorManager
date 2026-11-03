<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\HelpController;
use PHPUnit\Framework\TestCase;

/**
 * Hilfe-Bilder dürfen im Browser der lesenden Person liegen bleiben, aber in
 * keinem gemeinsamen Zwischenspeicher.
 *
 * Die Route liegt hinter der Anmeldung. Ein Screenshot aus dem Repository ist
 * kein Mitgliederdatum, und ihn einen Tag lang nicht erneut zu holen ist der
 * Zweck der Angabe. `public` erlaubt aber zusätzlich einem Reverse Proxy oder
 * CDN, die Antwort samt Kopfzeilen aufzubewahren und dem nächsten Abruf
 * auszuliefern - und das hat bei einer Antwort hinter der Anmeldung nichts zu
 * suchen. Genau daran hing auch die Sonderregel in CsrfMiddleware, die den
 * Token aus `public`-Antworten entfernt.
 *
 * `private, max-age=86400` erlaubt das eine und verbietet das andere.
 */
final class HelpImageCachePolicyFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    protected function setUp(): void
    {
        parent::setUp();
        // Die Navigationsleiste der Twig-Umgebung liest die Sitzung.
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function controller(): HelpController
    {
        return new HelpController($this->createAppTwig('/help'), dirname(__DIR__, 2) . '/help');
    }

    private function imageResponse(string $file): \Psr\Http\Message\ResponseInterface
    {
        return $this->controller()->image(
            $this->makeRequest('GET', '/help/images/' . $file),
            $this->makeResponse(),
            ['file' => $file]
        );
    }

    public function testHelpImageIsCacheableForTheBrowserOnly(): void
    {
        $response = $this->imageResponse('repertoire/08-downloads.png');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));

        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertStringNotContainsString(
            'public',
            $cacheControl,
            'Eine Antwort hinter der Anmeldung gehört in keinen gemeinsamen Zwischenspeicher.'
        );
    }

    public function testUnknownHelpImageIsNotFound(): void
    {
        $this->assertSame(404, $this->imageResponse('repertoire/gibt-es-nicht.png')->getStatusCode());
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->assertSame(404, $this->imageResponse('../../.env')->getStatusCode());
    }
}
