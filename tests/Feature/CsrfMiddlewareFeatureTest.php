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

    /**
     * Ein Ausnahmepfad bekommt keinen Token - und damit keine Sitzung.
     *
     * `ensureStarted()` und `ensureToken()` standen über der Ausnahmeprüfung.
     * Damit legte jede Anfrage an einen Ausnahmepfad eine Sitzung samt Token an:
     * jede WebDAV-Abfrage des Noten-Ordners, jede Rückmeldung eines
     * Mailproviders, jeder WOPI-Zugriff von Collabora, jeder Aufruf des
     * OIDC-Token-Endpunkts. Keiner dieser Absender zeigt Cookie oder Token je
     * wieder vor, und keiner der Controller liest die Sitzung - sie weisen sich
     * über Basic-Auth, Zugangstoken, Signatur bzw. Client-Secret aus. Seit die
     * Leerlaufgrenze bei acht Stunden liegt, blieb jede dieser Sitzungsdateien
     * acht Stunden liegen (Lauf 68).
     */
    public function testAnExemptPathGetsNoCsrfToken(): void
    {
        $middleware = new CsrfMiddleware();

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(207);
            }
        };

        $result = $middleware->process($this->makeRequest('PROPFIND', '/webdav/Projekt'), $handler);

        $this->assertSame(207, $result->getStatusCode());
        $this->assertArrayNotHasKey(Csrf::SESSION_KEY, $_SESSION);
        $this->assertFalse($result->hasHeader(Csrf::RESPONSE_HEADER));
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
     * Die Zeile muss sagen, woran die Prüfung scheiterte (Token fehlt oder passt nicht)
     * und ob die Person angemeldet war. Ohne das ließ sich eine verfallene Sitzung nicht
     * von einem veralteten Token unterscheiden.
     *
     * @return array<string, array{0: ?string, 1: bool, 2: string}>
     */
    public static function rejectionReasonProvider(): array
    {
        return [
            'Token fehlt' => [null, true, 'token_missing'],
            'Token passt nicht' => ['falsch', true, 'token_mismatch'],
            'nicht angemeldet' => ['falsch', false, 'token_mismatch'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectionReasonProvider')]
    public function testCsrfRejectionLogNamesTheReason(
        ?string $provided,
        bool $authenticated,
        string $expectedReason
    ): void {
        $handlerLog = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handlerLog);

        $middleware = new CsrfMiddleware($logger);
        unset($_SESSION['user_id'], $_SESSION[Csrf::SESSION_KEY]);
        if ($authenticated) {
            $_SESSION['user_id'] = 7;
        }
        $token = bin2hex(random_bytes(32));
        $request = $this->makeRequest('POST', '/profile', $provided === null ? [] : ['_csrf' => $provided]);
        $_SESSION[Csrf::SESSION_KEY] = $token;

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $middleware->process($request, $handler);

        $match = array_values(array_filter(
            $handlerLog->getRecords(),
            static fn ($record): bool => ($record->context['event'] ?? null) === 'security.csrf.rejected'
        ));

        $this->assertCount(1, $match);
        $this->assertSame($expectedReason, $match[0]->context['reason']);
        $this->assertSame($authenticated, $match[0]->context['authenticated']);
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

    /**
     * Eine Antwort, die sich selbst als `public` zwischenspeicherbar ausweist,
     * nennt den Token nicht.
     *
     * Heute tut das das OIDC-Discovery-Dokument samt Schlüsselsatz; die
     * Hilfe-Bilder taten es ebenfalls, bis sie auf `private` umgestellt wurden.
     * Beide liefen durch diese Middleware, und die hängte den Token der
     * aufrufenden Sitzung an.
     * Ein gemeinsamer Zwischenspeicher - Reverse Proxy, CDN - darf eine
     * `public`-Antwort samt Kopfzeilen aufbewahren und dem nächsten Abruf
     * ausliefern: Dann kennt eine fremde Person den Token einer anderen Sitzung,
     * und genau damit lässt sich die Fälschungs-Sperre aushebeln, gegen die der
     * Token schützt.
     *
     * Verloren geht dabei nichts: Diese Antworten sind ein Bild und ein
     * JSON-Dokument, kein Formular, das seinen Token nachziehen müsste.
     */
    public function testPubliclyCacheableResponseDoesNotCarryTheToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())
                    ->withHeader('Content-Type', 'image/png')
                    ->withHeader('Cache-Control', 'public, max-age=86400');
            }
        };

        $result = $middleware->process($this->makeRequest('GET', '/help/images/tasks/list.png'), $handler);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertFalse($result->hasHeader(Csrf::RESPONSE_HEADER));
    }

    /**
     * `private` mit Aufbewahrungsdauer bekommt den Token ebenfalls nicht.
     *
     * Hier stand die umgekehrte Erwartung, und ihre Begründung war, die Sperre
     * nähme den Seiten sonst ihren Token-Abgleich weg. Das trifft keine echte
     * Seite: Ohne eigene Angabe setzt die SecurityHeadersMiddleware `no-store`,
     * und genau zwei Routen weisen sich mit Dauer aus - die Hilfe-Bilder
     * (`private, max-age=86400`, HelpController::image) und das Logo
     * (`private, max-age=300`, AppSettingController). Beide sind ein Bild, kein
     * Formular, das seinen Token nachziehen müsste.
     *
     * Zwei Dinge sprechen dagegen, ihn dort zu lassen. Erstens liegt der Token
     * damit als einziges überhaupt auf der Platte: Alles andere trägt
     * `no-store`, diese beiden Antworten bleiben samt Kopfzeilen einen Tag bzw.
     * fünf Minuten im Zwischenspeicher des Browsers. Zweitens übernimmt
     * public/js/csrf-token-sync.js den Token aus **jeder** fetch-Antwort in
     * Seite und Formulare. Holte künftig ein fetch eine dieser Routen und der
     * Browser lieferte sie aus seinem Zwischenspeicher, überschriebe die Seite
     * ihren gültigen Token mit dem alten - und der nächste POST endete in
     * "Ungültiger CSRF-Token". Heute holt kein fetch eine solche Route; es ist
     * also keine Lücke, sondern eine Falle für den nächsten Umbau (Lauf 68).
     */
    public function testPrivatelyButDurablyCacheableResponseDoesNotCarryTheToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withHeader('Cache-Control', 'private, max-age=300');
            }
        };

        $result = $middleware->process($this->makeRequest('GET', '/settings/logo'), $handler);

        $this->assertFalse($result->hasHeader(Csrf::RESPONSE_HEADER));
    }

    /**
     * Was der Browser nicht aufbewahren darf, behält den Token - sonst nähme die
     * Sperre den Seiten ihren Abgleich weg. Das ist der Regelfall: Antworten
     * ohne eigene Angabe bekommen von der SecurityHeadersMiddleware `no-store`,
     * und zwei Routen sagen es selbst (HelpController::topics,
     * PublicFileLinkController).
     */
    public function testAResponseThatMustNotBeStoredStillCarriesTheToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withHeader('Cache-Control', 'private, no-store');
            }
        };

        $result = $middleware->process($this->makeRequest('GET', '/help/topics.json'), $handler);

        $this->assertSame($_SESSION[Csrf::SESSION_KEY], $result->getHeaderLine(Csrf::RESPONSE_HEADER));
    }

    /**
     * Der Regelfall einer Seite: keine eigene Angabe, also auch keine Dauer -
     * `no-store` setzt erst die SecurityHeadersMiddleware, und die hängt
     * außerhalb dieser Middleware.
     */
    public function testAResponseWithoutAnyCacheControlStillCarriesTheToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $result = $middleware->process($this->makeRequest('GET', '/dashboard'), $handler);

        $this->assertSame($_SESSION[Csrf::SESSION_KEY], $result->getHeaderLine(Csrf::RESPONSE_HEADER));
    }

    /**
     * `max-age=0` ist keine Aufbewahrung - der Token bleibt.
     */
    public function testAZeroMaxAgeIsNoStorageAndKeepsTheToken(): void
    {
        $middleware = new CsrfMiddleware();
        $_SESSION['user_id'] = 7;
        $_SESSION[Csrf::SESSION_KEY] = bin2hex(random_bytes(32));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withHeader('Cache-Control', 'no-store, max-age=0');
            }
        };

        $result = $middleware->process($this->makeRequest('GET', '/dashboard'), $handler);

        $this->assertSame($_SESSION[Csrf::SESSION_KEY], $result->getHeaderLine(Csrf::RESPONSE_HEADER));
    }
}
