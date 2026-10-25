<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\RefererTarget;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Der Rückverweis des Browsers ist die einzige Quelle für "zurück auf die Seite, von
 * der abgeschickt wurde" — und er kommt von außen. Ein Absender, der sein eigenes
 * `Referrer-Policy: unsafe-url` setzt, bestimmt den Pfad darin frei.
 *
 * Die UploadFileCountMiddleware prüfte ihn nur auf führendes `/` und `//`. Ein Pfad
 * wie `/\fremder.host` kam damit durch: Browser lesen den Rückwärtsschrägstrich einer
 * Adresse wie einen gewöhnlichen Schrägstrich, aus `/\fremder.host` wird im
 * `Location`-Kopf also `//fremder.host` — eine fremde Adresse. Diese Middleware läuft
 * zudem außerhalb der CSRF-Prüfung, ein fremdes Formular erreicht sie also.
 */
final class RefererTargetTest extends TestCase
{
    public function testSameOriginRefererBecomesPathAndQuery(): void
    {
        $request = $this->requestWithReferer('https://chor.example/song-library/7?tab=files');

        $this->assertSame('/song-library/7?tab=files', RefererTarget::resolve($request));
    }

    public function testBackslashPathIsRejected(): void
    {
        $request = $this->requestWithReferer('https://chor.example/\\fremder.host/x');

        $this->assertNull(RefererTarget::resolve($request));
    }

    public function testForeignOriginIsRejected(): void
    {
        $request = $this->requestWithReferer('https://fremder.host/song-library/7');

        $this->assertNull(RefererTarget::resolve($request));
    }

    public function testProtocolRelativePathIsRejected(): void
    {
        $request = $this->requestWithReferer('https://chor.example//fremder.host/x');

        $this->assertNull(RefererTarget::resolve($request));
    }

    public function testUnusableRefererIsRejected(): void
    {
        foreach (['', 'javascript:alert(1)', 'https://chor.example'] as $referer) {
            $request = $this->requestWithReferer($referer);

            $this->assertNull(RefererTarget::resolve($request), 'Referer: ' . $referer);
        }
    }

    private function requestWithReferer(string $referer): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://chor.example/song-library/7/attachments');

        return $referer === '' ? $request : $request->withHeader('Referer', $referer);
    }
}
