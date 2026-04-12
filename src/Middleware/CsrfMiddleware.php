<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class CsrfMiddleware implements MiddlewareInterface
{
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

        $parsedBody = $request->getParsedBody();
        $bodyToken = null;
        if (is_array($parsedBody)) {
            $candidate = $parsedBody['_csrf'] ?? null;
            if (is_scalar($candidate)) {
                $bodyToken = (string) $candidate;
            }
        }

        $headerToken = trim($request->getHeaderLine('X-CSRF-Token'));
        $providedToken = $bodyToken ?? ($headerToken !== '' ? $headerToken : null);

        if (Csrf::validate($providedToken)) {
            return $handler->handle($request);
        }

        $response = new SlimResponse(403);
        $accept = strtolower($request->getHeaderLine('Accept'));

        if (str_contains($accept, 'application/json')) {
            $response->getBody()->write((string) json_encode([
                'error' => 'Ungueltiger CSRF-Token',
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write('Ungueltiger CSRF-Token');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
