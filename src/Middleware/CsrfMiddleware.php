<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\Csrf;
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

        $response = new SlimResponse(403);
        $accept = strtolower($request->getHeaderLine('Accept'));

        if (str_contains($accept, 'application/json')) {
            $response->getBody()->write((string) json_encode([
                'error' => 'Ungültiger CSRF-Token',
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write('Ungültiger CSRF-Token');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
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
