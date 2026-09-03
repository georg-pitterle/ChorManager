<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\EnvHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        $allowsSelfFraming = $this->allowsSelfFraming($request);

        $response = $response
            ->withHeader('Content-Security-Policy', $this->buildCsp($allowsSelfFraming))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', $allowsSelfFraming ? 'SAMEORIGIN' : 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader(
                'Permissions-Policy',
                'camera=(), microphone=(), geolocation=(), accelerometer=(), gyroscope=(), magnetometer=()'
            );

        if ($this->isHttpsRequest($request) && EnvHelper::read('APP_ENV', 'development') === 'production') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $this->applyCachePolicy($response);
    }

    /**
     * Die Zwischenspeicherung entscheidet die Route, nicht diese Middleware: Wer
     * etwas ausliefert, das zwischengespeichert werden darf, setzt `Cache-Control`
     * selbst (etwa die Hilfe-Anhänge mit `public, max-age=86400`). Alles andere -
     * und das ist jede Seite hinter der Anmeldung - bekommt hier `no-store`, damit
     * Mitgliederdaten weder im Verlauf noch in einem Zwischenspeicher liegen
     * bleiben, wenn ein Gerät später jemand anderem gehört.
     */
    private function applyCachePolicy(Response $response): Response
    {
        if ($response->hasHeader('Cache-Control')) {
            return $response;
        }

        return $response
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Die beiden einzigen Ausnahmen vom vollständigen Framing-Verbot:
     *
     *  - die Route, die das fertige Mail-HTML eines gespeicherten Newsletters ausliefert. Sie
     *    dient als Quelle des eingebetteten Rahmens auf templates/newsletters/preview.twig.
     *  - die Anhang-Vorschau. Ein PDF lässt sich nur in einem Rahmen anzeigen; `object-src
     *    'none'` schließt `<embed>` und `<object>` aus, und ein neuer Tab wäre die Rückkehr
     *    zu genau der uneinheitlichen Bedienung, die das gemeinsame Modal ablöst.
     *
     * Vertretbar ist die zweite Ausnahme, weil die Route ausschließlich Dateiinhalte
     * ausliefert, kein HTML-Typ auf der Inline-Liste in App\Util\AttachmentPreview steht und
     * `X-Content-Type-Options: nosniff` gesetzt bleibt. Die Download-Route daneben behält
     * `'none'` - sie muss nie eingebettet werden.
     *
     * Jede andere Route bleibt uneingebettet - das ist der wirksamste Schutz gegen
     * Clickjacking.
     */
    private function allowsSelfFraming(Request $request): bool
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#^/newsletters/\d+/preview-frame$#', $path)) {
            return true;
        }

        return (bool) preg_match('#^/attachments/\d+/preview$#', $path);
    }

    private function buildCsp(bool $allowsSelfFraming): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            $allowsSelfFraming ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "media-src 'self' blob:",
        ]);
    }

    private function isHttpsRequest(Request $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        $forwardedProto = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $httpsServerValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        return $httpsServerValue !== '' && $httpsServerValue !== 'off';
    }
}
