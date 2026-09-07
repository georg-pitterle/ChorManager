<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\Csrf;
use App\Util\SafeRedirect;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response as SlimResponse;

class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Öffentliche Ingest-Endpunkte, deren Absender ein Mailprovider ist und kein
     * angemeldeter Browser. Sie haben keine Sitzung und damit keinen Token, den sie
     * mitschicken könnten; ausgewiesen wird sich stattdessen über den
     * ProviderWebhookVerifier (HMAC-Signatur bzw. Ingest-Token), noch bevor der
     * Controller die Nutzlast anfasst. Beide werten ausschließlich diesen Nachweis
     * aus und nie die Sitzung, weshalb hier nichts zu schützen ist - ohne diese
     * Ausnahme hat die Middleware jede Rückmeldung des Providers mit 403 abgewiesen
     * und Zustell- sowie Unzustellbarkeitsereignisse kamen nie an.
     *
     * @var list<string>
     */
    private const EXEMPT_PATHS = [
        '/mail/delivery/webhook',
        '/mail/delivery/dsn',
    ];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger = new NullLogger())
    {
        $this->logger = $logger;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        Csrf::ensureToken();

        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        if ($this->isExemptPath($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $parsedBody = $request->getParsedBody();
        $bodyToken = null;
        if (is_array($parsedBody)) {
            $candidate = $parsedBody['_csrf'] ?? null;
            // Ein leeres Feld zählt als "nicht gesendet": sonst verdeckt ein leerer
            // versteckter Eingabewert den ansonsten gültigen Header-Token.
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $bodyToken = trim((string) $candidate);
            }
        }

        $headerToken = trim($request->getHeaderLine('X-CSRF-Token'));
        $providedToken = $bodyToken ?? ($headerToken !== '' ? $headerToken : null);

        if (Csrf::validate($providedToken)) {
            return $handler->handle($request);
        }

        $this->logger->warning('CSRF token rejected.', [
            'event' => 'security.csrf.rejected',
        ]);

        $expectsJson = $this->expectsJson($request);

        // Der häufigste Weg hierher ist kein Angriff, sondern ein Formular, das
        // offen lag, bis die Sitzung ablief: Die neue Sitzung trägt einen neuen
        // Token, der alte im Formular passt nicht mehr. Weil diese Middleware vor
        // der AuthMiddleware prüft, bekam die Person eine weiße Seite mit
        // "Ungültiger CSRF-Token" und verlor ihre Eingaben, statt zur Anmeldung
        // geschickt zu werden.
        //
        // Die Weiterleitung schwächt nichts ab: Ohne Anmeldung wiese die
        // AuthMiddleware die Anfrage ohnehin genau dorthin ab, und eine
        // Fälschung gegen eine nicht angemeldete Sitzung hat kein Ziel. Für
        // JSON-Aufrufe bleibt es beim Fehler - die Oberfläche kann mit einer
        // Weiterleitung nichts anfangen.
        if (!$expectsJson && !isset($_SESSION['user_id'])) {
            return (new SlimResponse())
                ->withHeader('Location', $this->loginLocation($request))
                ->withStatus(302);
        }

        $response = new SlimResponse(403);

        if ($expectsJson) {
            $response->getBody()->write((string) json_encode([
                'error' => 'Ungültiger CSRF-Token',
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write('Ungültiger CSRF-Token');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Gleiche Erkennung wie in RoleMiddleware und den Controllern: Die
     * Oberfläche schickt je nach Aufrufstelle nur `X-Requested-With` (etwa
     * newsletters-edit.js) oder zusätzlich `Accept` (etwa registrations.js).
     *
     * Beide Formen müssen zählen. Ein Aufruf per `fetch` kann mit einer
     * Weiterleitung nichts anfangen - er folgt ihr, bekommt die Anmeldeseite als
     * HTML mit Status 200 zurück und scheitert erst beim Auswerten, mit einer
     * Meldung, die nichts mit der abgelaufenen Sitzung zu tun hat.
     */
    private function expectsJson(Request $request): bool
    {
        if (strtolower(trim($request->getHeaderLine('X-Requested-With'))) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * Wohin nach dem Ablauf der Sitzung - mit der Seite als Ziel, auf der das
     * Formular stand.
     *
     * Der Pfad der abgewiesenen Anfrage taugt dafür nicht: Zu einem POST-Ziel wie
     * `/profile/password` gehört gar keine Seite. Der Rückverweis des Browsers
     * nennt dagegen genau die Seite, von der abgeschickt wurde - bei gleicher
     * Herkunft schickt ihn der Browser vollständig mit, dafür sorgt die
     * `Referrer-Policy` aus der SecurityHeadersMiddleware.
     *
     * Geprüft wird er doppelt: Die Herkunft muss die eigene sein, und der übrige
     * Pfad muss `SafeRedirect` bestehen. Sonst wäre die Anmeldeseite über einen
     * gefälschten Rückverweis ein offener Weiterleiter.
     *
     * Lässt sich die Herkunft nicht vergleichen - kein Host in der Anfrage, oder
     * ein Proxy, der den Host-Kopf umschreibt -, bleibt es bei der nackten
     * Anmeldeseite. Das ist eine Unbequemlichkeit, kein Schaden.
     */
    private function loginLocation(Request $request): string
    {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return '/login';
        }

        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return '/login';
        }

        $uri = $request->getUri();
        $refererHost = strtolower((string) ($parts['host'] ?? ''));
        if ($refererHost !== '' && $refererHost !== strtolower($uri->getHost())) {
            return '/login';
        }

        $target = (string) ($parts['path'] ?? '');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $target .= '?' . $parts['query'];
        }

        $safeTarget = SafeRedirect::sanitize($target);
        if ($safeTarget === null || $safeTarget === '/login') {
            return '/login';
        }

        return '/login?redirect=' . rawurlencode($safeTarget);
    }

    /**
     * Vergleicht auf ganze Pfade, nicht auf Präfixe: `/mail/delivery/webhook/x` bleibt
     * damit geschützt, ein angehängter Schrägstrich hebt die Ausnahme aber nicht auf.
     */
    private function isExemptPath(string $path): bool
    {
        $normalizedPath = rtrim($path, '/');

        return $normalizedPath !== '' && in_array($normalizedPath, self::EXEMPT_PATHS, true);
    }
}
