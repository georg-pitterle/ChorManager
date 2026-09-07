<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\CsrfMiddleware;
use App\Util\Csrf;
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
}
