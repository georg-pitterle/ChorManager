<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Logging\RequestContext;
use App\Util\ClientIpResolver;
use App\Util\SessionConfig;
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

    /**
     * Die Sitzung wird hier nur gelesen, nicht angelegt.
     *
     * Vorher stand hier `ensureStarted()`, und weil diese Middleware ganz außen
     * hängt, legte damit jede Anfrage eine Sitzungsdatei an und bekam ein
     * Set-Cookie - auch der Noten-Ordner über WebDAV, die Rückmeldungen der
     * Mailprovider, Collabora über WOPI und der Token-Endpunkt des
     * OIDC-Providers. Keiner davon zeigt das Cookie je wieder vor, und seit die
     * Leerlaufgrenze bei acht Stunden liegt, blieb jede dieser Dateien acht
     * Stunden liegen. Eine Benutzerkennung stand darin nie: Wer sich nicht
     * angemeldet hat, hat keine.
     *
     * Anfragen mit Sitzung sind unberührt - sie bringen das Cookie mit.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        SessionConfig::ensureStartedForKnownSession();

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
