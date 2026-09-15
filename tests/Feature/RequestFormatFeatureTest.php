<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\RequestFormat;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Die Erkennung "will dieser Aufruf JSON?" stand wortgleich in der
 * CsrfMiddleware, der RoleMiddleware und drei Controllern. Jetzt steht sie
 * einmal - dieser Test hält das Verhalten fest, das alle fünf Abschriften
 * hatten, damit die Zusammenlegung nachweisbar nichts verschiebt.
 */
final class RequestFormatFeatureTest extends TestCase
{
    public function testXRequestedWithHeaderCountsRegardlessOfCasingAndSpacing(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/users')
            ->withHeader('X-Requested-With', '  XmlHttpRequest  ');

        $this->assertTrue(RequestFormat::expectsJson($request));
    }

    public function testAcceptHeaderWithJsonCountsEvenAlongsideOtherTypes(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/users')
            ->withHeader('Accept', 'text/html, APPLICATION/JSON;q=0.9');

        $this->assertTrue(RequestFormat::expectsJson($request));
    }

    public function testPlainBrowserRequestDoesNotExpectJson(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/users')
            ->withHeader('Accept', 'text/html,application/xhtml+xml');

        $this->assertFalse(RequestFormat::expectsJson($request));
    }

    public function testRequestWithoutAnyHintDoesNotExpectJson(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/users');

        $this->assertFalse(RequestFormat::expectsJson($request));
    }

    /**
     * Ein `fetch`-Aufruf kann mit einer Weiterleitung nichts anfangen. Deshalb
     * müssen alle Stellen, die zwischen JSON und Weiterleitung entscheiden,
     * dieselbe Erkennung benutzen - und keine davon eine eigene Abschrift.
     */
    public function testNoCopyOfTheDetectionIsLeftBehind(): void
    {
        $paths = [
            'src/Middleware/CsrfMiddleware.php',
            'src/Middleware/RoleMiddleware.php',
            'src/Controllers/NewsletterController.php',
            'src/Controllers/NewsletterTemplateController.php',
            'src/Controllers/RegistrationController.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'private function expectsJson(',
                $source,
                $path . ' trägt wieder eine eigene Abschrift statt RequestFormat::expectsJson().'
            );
            $this->assertStringContainsString('RequestFormat::expectsJson(', $source, $path);
        }
    }
}
