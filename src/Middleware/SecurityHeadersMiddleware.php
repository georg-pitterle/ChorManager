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
    public function __construct(private readonly string $officeOrigin = '')
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        $allowsSelfFraming = $this->allowsSelfFraming($request);

        $response = $response
            ->withHeader('Content-Security-Policy', $this->buildCsp($allowsSelfFraming, $this->embedsOffice($request)))
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
     * Die einzige Ausnahme vom vollständigen Framing-Verbot: die Route, die das fertige
     * Mail-HTML eines gespeicherten Newsletters ausliefert. Sie dient als Quelle des
     * eingebetteten Rahmens auf templates/newsletters/preview.twig.
     *
     * Die Anhang-Vorschau stand hier ebenfalls, solange ein PDF im Modal in einem iframe
     * hing. Seit pdf.js die Seiten selbst auf ein Canvas zeichnet, wird dort nichts mehr
     * eingebettet - und eine Lockerung, die niemand nutzt, gehört weg, statt auf den
     * nächsten Umbau zu warten, der sich versehentlich darauf stützt.
     *
     * Jede andere Route bleibt uneingebettet - das ist der wirksamste Schutz gegen
     * Clickjacking.
     */
    private function allowsSelfFraming(Request $request): bool
    {
        return (bool) preg_match('#^/newsletters/\d+/preview-frame$#', $request->getUri()->getPath());
    }

    /**
     * Die Editor-Seite der Dateiablage bettet Collabora ein: Ihr Formular schickt das
     * Zugangstoken per POST in den Rahmen. Nur dort und nur für genau den Ursprung aus
     * OFFICE_SERVER_URL öffnen sich frame-src und form-action.
     */
    private function embedsOffice(Request $request): bool
    {
        return $this->officeOrigin !== ''
            && (bool) preg_match('#^/files/\d+/edit$#', $request->getUri()->getPath());
    }

    private function buildCsp(bool $allowsSelfFraming, bool $embedsOffice): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            $allowsSelfFraming ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            $embedsOffice ? "form-action 'self' " . $this->officeOrigin : "form-action 'self'",
            // 'wasm-unsafe-eval' erlaubt pdf.js das Übersetzen seiner JBIG2-/JPEG2000-Module.
            // Es erlaubt kein eval() für JavaScript, und woher die Bytes stammen dürfen, regeln
            // weiterhin default-src/connect-src 'self' - also nur die eigene Herkunft.
            "script-src 'self' 'wasm-unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "media-src 'self' blob:",
        ];
        if ($embedsOffice) {
            $directives[] = 'frame-src ' . $this->officeOrigin;
        }

        return implode('; ', $directives);
    }

    /**
     * Gefragt wird der Request, nicht `$_SERVER`.
     *
     * Die Angabe stand bis hierher aus dem Superglobal, und das ist prozessweit:
     * Im Testlauf setzt eine Testklasse `HTTPS` und räumt es nicht ab, in einem
     * dauerhaft laufenden SAPI steht dort der Wert der vorherigen Anfrage. Die
     * Middleware antwortete dann für eine Klartext-Anfrage mit HSTS - und war je
     * Anfrage überhaupt nicht prüfbar, weil der Wert nicht am Request hing. Die
     * Serverwerte des Requests tragen dieselbe Angabe, nur eben die dieser einen
     * Anfrage; Slim füllt sie beim Einstieg aus `$_SERVER`.
     */
    private function isHttpsRequest(Request $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        $forwardedProto = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $httpsServerValue = strtolower(trim((string) ($request->getServerParams()['HTTPS'] ?? '')));
        return $httpsServerValue !== '' && $httpsServerValue !== 'off';
    }
}
