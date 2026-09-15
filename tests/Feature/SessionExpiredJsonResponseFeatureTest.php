<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Queries\UserQuery;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\Csrf;
use App\Util\SessionExpiredSignal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Läuft die Sitzung ab, während jemand arbeitet, bekam ein `fetch`-Aufruf eine
 * Weiterleitung auf `/login`. `fetch` folgt ihr selbst, bekommt die Anmeldeseite
 * als HTML mit Status 200 zurück und scheitert erst beim Auswerten - die
 * Oberfläche zeigte dann "Speichern fehlgeschlagen", was mit der Ursache nichts
 * zu tun hat.
 *
 * Jetzt bekommt ein solcher Aufruf einen Fehler als Fehler, und zwar an beiden
 * Stellen, die ihn abweisen können. Welche das ist, hängt an der Methode:
 *
 * - GET geht an der CsrfMiddleware vorbei und wird von der AuthMiddleware
 *   abgewiesen (401).
 * - POST erreicht die AuthMiddleware gar nicht erst: Die CsrfMiddleware läuft
 *   davor, und mit der Sitzung ist auch der Token weg (403).
 *
 * Beide tragen denselben Merker im Kopf der Antwort, damit die Oberfläche den
 * abgelaufenen Zustand von einem echten Rechte- oder Token-Fehler unterscheiden
 * kann, ohne den Rumpf zu lesen.
 */
final class SessionExpiredJsonResponseFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        unset($_COOKIE[RememberLoginService::COOKIE_NAME]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE[RememberLoginService::COOKIE_NAME]);

        parent::tearDown();
    }

    public function testFetchGetOnAProtectedRouteGets401InsteadOfARedirect(): void
    {
        $response = $this->authMiddleware()->process(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/users/42')
                ->withHeader('X-Requested-With', 'XMLHttpRequest'),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('1', $response->getHeaderLine(SessionExpiredSignal::HEADER));
        $this->assertSame('', $response->getHeaderLine('Location'));

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        // `error` liest newsletters.js, `message` liest users.js - beide tragen
        // denselben Text, damit kein Aufrufer angepasst werden muss.
        $this->assertSame(SessionExpiredSignal::MESSAGE, $payload['error']);
        $this->assertSame(SessionExpiredSignal::MESSAGE, $payload['message']);
    }

    /**
     * Auch der Aufruf, der sich nur über `Accept` ausweist - registrations.js
     * etwa schickt beides, andere Stellen nur eines von beidem.
     */
    public function testAcceptHeaderAloneIsEnoughToGetTheJsonAnswer(): void
    {
        $response = $this->authMiddleware()->process(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/users/42')
                ->withHeader('Accept', 'application/json'),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('1', $response->getHeaderLine(SessionExpiredSignal::HEADER));
    }

    /**
     * Der gewöhnliche Seitenaufruf im Browser behält die Weiterleitung mitsamt
     * Ziel - er kann damit etwas anfangen, ein 401 wäre dort eine Sackgasse.
     */
    public function testPlainBrowserRequestKeepsTheRedirectToLogin(): void
    {
        $response = $this->authMiddleware()->process(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/users/42')
                ->withHeader('Accept', 'text/html,application/xhtml+xml'),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?redirect=%2Fusers%2F42', $response->getHeaderLine('Location'));
        $this->assertFalse($response->hasHeader(SessionExpiredSignal::HEADER));
    }

    /**
     * Der häufigere Weg: Ein POST per `fetch` erreicht die AuthMiddleware nie,
     * weil mit der Sitzung auch der CSRF-Token weg ist und die CsrfMiddleware
     * davor läuft. Ohne denselben Merker hier bliebe die Korrektur oben für
     * Mitgliederverwaltung, Newsletter und Anmeldungen wirkungslos.
     */
    public function testFetchPostWithAnExpiredSessionIsMarkedAsExpiredToo(): void
    {
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $response = (new CsrfMiddleware())->process(
            $this->makeRequest(
                'POST',
                '/users/42/roles',
                ['_csrf' => 'veralteter-token'],
                [],
                ['X-Requested-With' => 'XMLHttpRequest']
            ),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('1', $response->getHeaderLine(SessionExpiredSignal::HEADER));

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame(SessionExpiredSignal::MESSAGE, $payload['error']);
    }

    /**
     * Ein angemeldeter Aufruf mit falschem Token ist kein Sitzungsablauf,
     * sondern der Fall, für den die Prüfung da ist. Trüge er den Merker, lüde
     * die Oberfläche bei jedem echten Angriffsversuch die Seite neu und
     * verdeckte ihn damit.
     */
    public function testAuthenticatedRequestWithABadTokenIsNotMarkedAsExpired(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $response = (new CsrfMiddleware())->process(
            $this->makeRequest(
                'POST',
                '/users/42/roles',
                ['_csrf' => 'falscher-token'],
                [],
                ['X-Requested-With' => 'XMLHttpRequest']
            ),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->hasHeader(SessionExpiredSignal::HEADER));
    }

    /**
     * Die Oberfläche muss den Merker an einer Stelle auswerten, nicht in elf.
     *
     * common.js liegt in layout.twig vor den seitenweisen Skripten und legt sich
     * deshalb um `window.fetch`, statt jeden Aufrufer einzeln anzufassen - sonst
     * bliebe der nächste neu geschriebene `fetch`-Aufruf wieder ohne Behandlung.
     */
    public function testTheFrontendHandlesTheSignalInOnePlace(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/common.js');

        $this->assertStringContainsString(SessionExpiredSignal::HEADER, $script);
        $this->assertStringContainsString('window.fetch', $script);
        $this->assertStringContainsString('/login?redirect=', $script);

        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout.twig');
        $commonPosition = strpos($layout, '/js/common.js');
        $blockPosition = strpos($layout, '{% block scripts %}');
        $this->assertIsInt($commonPosition);
        $this->assertIsInt($blockPosition);
        $this->assertLessThan(
            $blockPosition,
            $commonPosition,
            'common.js muss vor den seitenweisen Skripten stehen, sonst greift der Ersatz zu spät.'
        );
    }

    private function authMiddleware(): AuthMiddleware
    {
        return new AuthMiddleware(
            $this->createStub(UserQuery::class),
            $this->createStub(RememberLoginService::class),
            $this->createStub(SessionAuthService::class)
        );
    }

    private function handlerThatMustNotRun(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Eine abgewiesene Anfrage darf den Handler nie erreichen.');
            }
        };
    }
}
