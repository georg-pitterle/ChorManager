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
     * Derselbe Anspruch, nur ohne Liste: Die beiden Kopfzeilen, aus denen sich
     * die Erkennung speist, liest ausschließlich RequestFormat.
     *
     * Die Liste darüber nennt Dateien und ließ damit genau die Stellen durch,
     * die ihre Abschrift anders benannten: ProfileController hieß sie
     * `wantsJsonResponse()` und las nur `Accept`, FileControllerSupport
     * `wantsJson()` und verglich `X-Requested-With` buchstabengenau,
     * UserNotificationController legte sie gar nicht in eine Methode. Alle drei
     * entschieden damit anders als AuthMiddleware, CsrfMiddleware und
     * RoleMiddleware vor ihnen: Ein `fetch`, das nur `X-Requested-With`
     * mitschickt, bekam von der Middleware JSON und vom Controller eine
     * Weiterleitung - also die Anmelde- oder Formularseite als HTML in einem
     * Aufruf, der JSON auswertet.
     *
     * Diese Prüfung kennt keine Liste und erfasst deshalb auch die nächste
     * Abschrift, wie sie auch heißt.
     */
    public function testOnlyRequestFormatReadsTheTwoHeadersTheDetectionRestsOn(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $allowed = $srcDir . '/Util/RequestFormat.php';

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || $file->getPathname() === $allowed) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match("/getHeaderLine\\(\\s*['\\\"](X-Requested-With|Accept)['\\\"]/i", $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Diese Dateien lesen X-Requested-With oder Accept selbst, statt RequestFormat::expectsJson() zu fragen.'
        );
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
