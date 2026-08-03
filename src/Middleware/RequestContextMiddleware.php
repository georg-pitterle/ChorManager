<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Logging\RequestContext;
use App\Util\ClientIpResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Erzeugt je Request eine Kennung und legt sie mit Methode, Pfad und IP im
 * Logging-Kontext ab.
 */
final class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;

        $this->context->assign([
            'request_id' => bin2hex(random_bytes(8)),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'ip' => ClientIpResolver::resolve($request),
        ]);
        $this->context->setUserId(is_numeric($userId) ? (int) $userId : null);

        return $handler->handle($request);
    }
}
