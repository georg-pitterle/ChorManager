<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\CsrfMiddleware;
use App\Services\RememberLoginRestoreService;
use App\Util\Csrf;
use App\Util\SessionExpiredSignal;
use Psr\Log\NullLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CsrfMiddlewareFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        // Die Sitzung hier starten und nicht erst in der Middleware: Deren
        // session_start() ersetzt $_SESSION durch den (leeren) gespeicherten
        // Stand und warf damit alles weg, was der Test zuvor hineingelegt hat.
        // Allein aufgerufen fiel ein Test dadurch um, im Klassenlauf nicht -
        // dort hatte ein Vorgänger die Sitzung längst gestartet.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
    }

    public function testGetRequestPassesWithoutCsrfValidation(): void
    {
        $middleware = new CsrfMiddleware();
        $request = $this->makeRequest('GET', '/dashboard');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(204);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(204, $result->getStatusCode());
        $this->assertArrayHasKey(Csrf::SESSION_KEY, $_SESSION);
    }

    public function testPostRequestWithValidTokenPasses(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $request = $this->makeRequest('POST', '/profile', [
            '_csrf' => $_SESSION[Csrf::SESSION_KEY],
        ]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testEmptyBodyTokenDoesNotShadowValidHeaderToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $request = $this->makeRequest(
            'POST',
            '/profile',
            ['_csrf' => ''],
            [],
            ['X-CSRF-Token' => $_SESSION[Csrf::SESSION_KEY]]
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testSignatureVerifiedIngestEndpointsPassWithoutCsrfToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        // Absender ist der Mailprovider, nicht der Browser eines Mitglieds: ohne Sitzung
        // gibt es keinen Token, den er mitschicken könnte. Beide Endpunkte weisen sich
        // stattdessen über ProviderWebhookVerifier aus.
        foreach (['/mail/delivery/webhook', '/mail/delivery/dsn'] as $path) {
            $result = $middleware->process($this->makeRequest('POST', $path), $handler);

            $this->assertSame(200, $result->getStatusCode(), $path);
        }
    }

    public function testOidcTokenEndpointPassesWithoutCsrfTokenButItsNeighboursDoNot(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        // Die angeschlossene Anwendung spricht von Server zu Server: keine
        // Sitzung, kein Token - ausgewiesen wird sich mit Client-Secret und
        // code_verifier, die TokenController vor allem anderen prüft.
        $passed = $middleware->process($this->makeRequest('POST', '/oidc/token'), $handler);
        $this->assertSame(200, $passed->getStatusCode());

        // Die Ausnahme gilt genau diesem einen Pfad und keinem darunter.
        foreach (['/oidc/token/extra', '/oidc/authorize', '/profile'] as $path) {
            $blocked = $middleware->process($this->makeRequest('POST', $path), $handler);
            $this->assertSame(403, $blocked->getStatusCode(), $path);
        }
    }

    public function testWopiEndpointsPassWithoutCsrfTokenButNeighboursDoNot(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        // Collabora speichert von Server zu Server: keine Sitzung, kein CSRF-Token.
        // Ausgewiesen wird sich mit dem Zugangstoken, das WopiController prüft.
        $passed = $middleware->process($this->makeRequest('POST', '/wopi/files/5/contents'), $handler);
        $this->assertSame(200, $passed->getStatusCode());

        foreach (['/wopifoo', '/files/5/edit', '/files/5/replace'] as $path) {
            $blocked = $middleware->process($this->makeRequest('POST', $path), $handler);
            $this->assertSame(403, $blocked->getStatusCode(), $path);
        }
    }

    public function testPathBelowAnIngestEndpointStaysCsrfProtected(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process(
            $this->makeRequest('POST', '/mail/delivery/webhook/anything'),
            $handler
        );

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testPostRequestWithInvalidTokenReturns403(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $request = $this->makeRequest('POST', '/profile', [
            '_csrf' => 'invalid-token',
        ]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($request, $handler);

        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }

    /**
     * Der häufigste Weg zu einem ungültigen Token ist kein Angriff, sondern ein
     * Formular, das offen lag, bis die Sitzung ablief. Die neue Sitzung trägt
     * einen neuen Token, der alte im Formular passt nicht mehr - und weil die
     * CsrfMiddleware vor der AuthMiddleware prüft, bekam die Person eine weiße
     * Seite mit "Ungültiger CSRF-Token" statt der Anmeldung.
     */
    public function testExpiredSessionIsSentToTheLoginPageInsteadOf403(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        // Absolute Adresse wie bei einer echten Anfrage: Der Abgleich des
        // Rückverweises braucht den Host der Anfrage als Vergleichswert.
        $request = $this->makeRequest(
            'POST',
            'http://localhost/profile',
            ['_csrf' => 'stale-token'],
            [],
            ['Referer' => 'http://localhost/profile']
        );

        $result = $middleware->process($request, $this->handlerThatMustNotRun());

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/login?redirect=%2Fprofile', $result->getHeaderLine('Location'));
    }

    /**
     * Ohne verwertbaren Rückverweis bleibt es bei der nackten Anmeldeseite: Der
     * Pfad der abgewiesenen POST-Anfrage taugt nicht als Ziel, weil zu ihm oft
     * gar keine Seite gehört (`/profile/password` etwa).
     */
    public function testExpiredSessionWithoutUsableRefererGoesToPlainLogin(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', '/profile/password', ['_csrf' => 'stale-token']),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/login', $result->getHeaderLine('Location'));
    }

    /**
     * Ein Rückverweis fremder Herkunft ist kein Ziel, auf das weitergeleitet
     * werden darf - sonst wäre die Anmeldeseite ein offener Weiterleiter.
     */
    public function testForeignRefererIsNotUsedAsRedirectTarget(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest(
                'POST',
                'http://localhost/profile',
                ['_csrf' => 'stale-token'],
                [],
                ['Referer' => 'https://angreifer.example/profile']
            ),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame('/login', $result->getHeaderLine('Location'));
    }

    /**
     * Wer angemeldet ist, hat einen gültigen Token bekommen - ein falscher ist
     * dann keine abgelaufene Sitzung mehr, sondern bleibt eine Abweisung.
     */
    public function testAuthenticatedRequestWithInvalidTokenStillGets403(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', '/profile', ['_csrf' => 'invalid-token']),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
    }

    /**
     * Die Oberfläche wertet JSON aus und kann mit einer Weiterleitung nichts
     * anfangen; sie braucht den Fehler als Fehler.
     */
    public function testJsonRequestWithExpiredSessionStillGets403(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest(
                'POST',
                '/profile',
                ['_csrf' => 'stale-token'],
                [],
                ['Accept' => 'application/json']
            ),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
    }

    /**
     * Nicht jede Stelle der Oberfläche schickt `Accept` mit - newsletters-edit.js
     * etwa weist sich nur über `X-Requested-With` aus. Auch dieser Aufruf muss
     * den Fehler als Fehler bekommen: Einer Weiterleitung folgt `fetch` selbst,
     * bekommt die Anmeldeseite mit Status 200 und scheitert erst beim Auswerten.
     */
    public function testXmlHttpRequestWithoutAcceptHeaderStillGets403(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest(
                'POST',
                'http://localhost/newsletters/resolve-recipients-preview',
                ['_csrf' => 'stale-token'],
                [],
                ['X-Requested-With' => 'XMLHttpRequest', 'Referer' => 'http://localhost/newsletters/1/edit']
            ),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
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

    public function testPostRequestWithInvalidTokenLogsCsrfRejected(): void
    {
        $handlerLog = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handlerLog);

        $middleware = new CsrfMiddleware($logger);
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $request = $this->makeRequest('POST', '/profile', [
            '_csrf' => 'invalid-token',
        ]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $middleware->process($request, $handler);

        $records = $handlerLog->getRecords();
        $match = array_values(array_filter(
            $records,
            static fn ($record): bool => ($record->context['event'] ?? null) === 'security.csrf.rejected'
        ));

        $this->assertNotEmpty($match);
        $this->assertSame(Logger::WARNING, $match[0]->level->value);
    }

    /**
     * Der Weg, auf dem der Newsletter-Editor seinen Token verlor: Die Sitzung
     * verfiel, während die Seite offen lag. Beim Zurückwechseln fragte die
     * Glocke nach, die AuthMiddleware stellte die Sitzung per Remember-Me her
     * und drehte dabei den Token - innerhalb genau dieser Anfrage. Die offene
     * Seite erfuhr davon nichts, und ihr nächstes Speichern lief in
     * "Ungültiger CSRF-Token". Die Antwort nennt deshalb den Token, der nach
     * der Anfrage gilt, nicht den von vorher.
     */
    public function testResponseCarriesTheTokenThatIsValidAfterTheRequest(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                Csrf::rotate();

                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($this->makeRequest('GET', '/notifications/count'), $handler);

        $this->assertSame($_SESSION[Csrf::SESSION_KEY], $result->getHeaderLine(Csrf::RESPONSE_HEADER));
    }

    /**
     * Auch die Abweisung nennt den gültigen Token: Die Seite kann damit den
     * nächsten Versuch richtig stellen, statt bis zum Neuladen jeden weiteren
     * Aufruf abgewiesen zu bekommen. Lesen kann den Kopf nur Skript derselben
     * Herkunft - dasselbe, das den Token ohnehin im `<meta>` der Seite findet.
     */
    public function testRejectedRequestOfAnAuthenticatedSessionCarriesTheValidToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', '/newsletters/1', ['_csrf' => 'stale-token'], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame($_SESSION[Csrf::SESSION_KEY], $result->getHeaderLine(Csrf::RESPONSE_HEADER));
    }

    /**
     * Ein Wiederherstellungsdienst, der wie RememberLoginRestoreService eine
     * Sitzung aufbaut: Person setzen, Token drehen.
     */
    private function restoreThatSucceeds(): RememberLoginRestoreService
    {
        $restore = $this->createStub(RememberLoginRestoreService::class);
        $restore->method('restoreFromCookie')->willReturnCallback(static function (): bool {
            $_SESSION['user_id'] = 42;
            Csrf::rotate();

            return true;
        });

        return $restore;
    }

    /**
     * Der Randfall hinter dem Newsletter-Fehler: Ist nach dem Verfallen der Sitzung
     * die ERSTE Anfrage ein POST - etwa das automatische Speichern des Editors -,
     * schickte die Seite bisher zur Anmeldung. Jetzt baut die Middleware die
     * Sitzung per Remember-Me selbst auf. Den POST nimmt sie trotzdem nicht an:
     * Sein Token gehört zur alten Sitzung. Sie nennt der Seite den neuen Token und
     * bittet um eine Wiederholung - lesen kann beides nur Skript derselben Herkunft.
     */
    public function testJsonPostWithoutSessionRestoresItAndAsksForARetry(): void
    {
        $middleware = new CsrfMiddleware(new NullLogger(), $this->restoreThatSucceeds());
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', '/newsletters/26', ['_csrf' => 'token-of-the-lost-session'], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame('1', $result->getHeaderLine(Csrf::RETRY_HEADER));
        $this->assertSame($_SESSION[Csrf::SESSION_KEY], $result->getHeaderLine(Csrf::RESPONSE_HEADER));
        $this->assertFalse(
            $result->hasHeader(SessionExpiredSignal::HEADER),
            'Die Sitzung ist wieder da - die Seite darf nicht zur Anmeldung geschickt werden.'
        );
        $this->assertSame(42, $_SESSION['user_id']);
    }

    /**
     * Ein klassisch abgeschicktes Formular lässt sich nicht still wiederholen. Es
     * geht zurück auf die Seite, von der es kam - angemeldet, statt über die
     * Anmeldeseite -, mit dem Hinweis, noch einmal abzusenden.
     */
    public function testFormPostWithoutSessionRestoresItAndReturnsToTheFormPage(): void
    {
        $middleware = new CsrfMiddleware(new NullLogger(), $this->restoreThatSucceeds());
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', 'http://localhost/newsletters/26/send', ['_csrf' => 'stale'], [], [
                'Referer' => 'http://localhost/newsletters?status=draft',
            ]),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/newsletters?status=draft', $result->getHeaderLine('Location'));
        $this->assertStringContainsString('noch einmal', (string) ($_SESSION['warning'] ?? ''));
    }

    /**
     * Ohne gültiges Remember-Me-Cookie bleibt alles beim Alten: Es gibt nichts
     * wiederherzustellen, die Sitzung ist abgelaufen.
     */
    public function testJsonPostWithoutSessionAndWithoutRememberCookieStillSignalsExpiry(): void
    {
        $restore = $this->createStub(RememberLoginRestoreService::class);
        $restore->method('restoreFromCookie')->willReturn(false);
        $middleware = new CsrfMiddleware(new NullLogger(), $restore);
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', '/newsletters/26', ['_csrf' => 'stale'], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame('1', $result->getHeaderLine(SessionExpiredSignal::HEADER));
        $this->assertFalse($result->hasHeader(Csrf::RETRY_HEADER));
    }

    /**
     * Wer angemeldet ist und einen falschen Token schickt, bekommt keine
     * Wiederholung angeboten: Das ist der Fall, für den die Prüfung da ist.
     */
    public function testAuthenticatedRequestWithInvalidTokenIsNotOfferedARetry(): void
    {
        $middleware = new CsrfMiddleware(new NullLogger(), $this->restoreThatSucceeds());
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $result = $middleware->process(
            $this->makeRequest('POST', '/newsletters/26', ['_csrf' => 'forged'], [], [
                'X-Requested-With' => 'XMLHttpRequest',
            ]),
            $this->handlerThatMustNotRun()
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertFalse($result->hasHeader(Csrf::RETRY_HEADER));
        $this->assertSame(7, $_SESSION['user_id']);
    }

    /**
     * Die ausgenommenen Endpunkte sprechen mit Mailprovidern, WebDAV- und
     * Office-Clients - keiner davon braucht den Token, und keiner soll ihn sehen.
     */
    public function testExemptPathResponseDoesNotCarryTheToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $result = $middleware->process($this->makeRequest('POST', '/mail/delivery/webhook'), $handler);

        $this->assertFalse($result->hasHeader(Csrf::RESPONSE_HEADER));
    }
}
