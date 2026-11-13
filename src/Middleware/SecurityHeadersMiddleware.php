<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\ClientIpResolver;
use App\Util\EnvHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param (\Closure(): list<string>)|null $loginFormTargets Ursprünge der angeschlossenen
     *        OIDC-Anwendungen, siehe OidcRedirectOrigins; null bei ausgeschaltetem Modul.
     */
    public function __construct(
        private readonly string $officeOrigin = '',
        private readonly ?\Closure $loginFormTargets = null
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        $allowsSelfFraming = $this->allowsSelfFraming($request);
        $embedsOffice = $this->embedsOffice($request);

        $formTargets = $embedsOffice ? [$this->officeOrigin] : $this->loginFormTargets($request);

        $response = $response
            ->withHeader('Content-Security-Policy', $this->buildCsp($allowsSelfFraming, $embedsOffice, $formTargets))
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
     * selbst (die Hilfe-Bilder mit `private, max-age=86400`, das
     * OIDC-Discovery-Dokument mit `public`). Alles andere -
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

    /**
     * Die Anmeldung für eine angeschlossene Anwendung endet mit einer Weiterleitung
     * dorthin: Login-Formular, /oidc/authorize, Rücksprungadresse. Chrome prüft
     * form-action auch auf diese Weiterleitungen, mit 'self' allein blieb das Mitglied
     * nach dem Absenden auf der Login-Seite stehen. Nur dort öffnet sich form-action
     * deshalb für die Ursprünge der eingetragenen Anwendungen.
     *
     * Die Ursprünge stehen in der Datenbank und werden nur für diese eine Seite
     * gelesen, nicht bei jeder Anfrage.
     *
     * @return list<string>
     */
    private function loginFormTargets(Request $request): array
    {
        if ($this->loginFormTargets === null || $request->getUri()->getPath() !== '/login') {
            return [];
        }

        return ($this->loginFormTargets)();
    }

    /**
     * @param list<string> $formTargets
     */
    private function buildCsp(bool $allowsSelfFraming, bool $embedsOffice, array $formTargets): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            $allowsSelfFraming ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            implode(' ', ["form-action 'self'", ...$formTargets]),
            // 'wasm-unsafe-eval' erlaubt pdf.js das Übersetzen seiner JBIG2-/JPEG2000-Module.
            // Es erlaubt kein eval() für JavaScript, und woher die Bytes stammen dürfen, regeln
            // weiterhin default-src/connect-src 'self' - also nur die eigene Herkunft.
            "script-src 'self' 'wasm-unsafe-eval'",
            // 'unsafe-inline' bleibt, und das ist eine gemessene Entscheidung, keine
            // Nachlässigkeit. Naheliegend wäre
            // `style-src 'self'; style-src-attr 'unsafe-inline'`: Das ließe die
            // dynamischen CSS-Variablen aus template-hygiene.md weiterlaufen und
            // nähme einem eingeschleusten <style>-Block die Wirkung. Zwei
            // Bibliotheken hängen ihre Gestaltung aber zur Laufzeit als <style> ein,
            // und verschärft bricht genau das:
            //
            // - TinyMCE schiebt `content_style` als <style> in den Editor-iframe, und
            //   der iframe erbt die Richtlinie der Seite. Abgewiesen stand `body` im
            //   Newsletter-Editor auf 16px statt 14px und `.newsletter-lead` nicht mehr
            //   auf 600/18px - wer schreibt, sieht dann nicht mehr, wie die Mail
            //   aussehen wird, und genau dafür steht `content_style` dort.
            // - html-midi-player (songs/downloads) baut seine Gestaltung als <style> im
            //   Shadow-DOM und nutzt kein `adoptedStyleSheets`. Abgewiesen stand der
            //   Player auf `display: inline` statt `inline-block` und `width: auto`
            //   statt `300px`.
            //
            // pdf.js ist nicht betroffen - es nimmt `adoptedStyleSheets`, wenn es sie
            // gibt. Gemessen in Chromium gegen die echten Dateien aus public/vendor,
            // beide Richtlinien gegeneinander (Review-Lauf 61).
            //
            // Der Gewinn wäre ohnehin klein: Ein eingeschleuster <style>-Block kann
            // Seiten verunstalten, aber keine Daten abziehen - dafür sorgen
            // script-src und connect-src 'self'. Vor einer Verschärfung müsste erst
            // `content_style` in eine echte CSS-Datei umziehen, und für den
            // MIDI-Player blieb nur ein CSP-Hash, der bei jedem Update der Bibliothek
            // still bricht.
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
     *
     * Drei Quellen, und nur die mittlere braucht eine Vertrauensprüfung:
     *
     * - Das Schema der Anfrage ist die Anfrage selbst.
     * - `X-Forwarded-Proto` darf jeder Absender setzen. Hinter einem TLS-beendenden
     *   Proxy ist die Kopfzeile die einzige Quelle für das ursprüngliche Protokoll,
     *   von einem beliebigen Absender ist sie eine Behauptung - sie zählt deshalb
     *   nur, wenn die Gegenstelle als vertrauter Proxy eingetragen ist. Dieselbe
     *   Regel wenden `SessionConfig::shouldUseSecureCookie()` und `AppUrlResolver`
     *   seit längerem an; hier fehlte sie als einzige Stelle. Gelesen wird der
     *   erste Eintrag der Kette - er stammt von der Gegenstelle, die den Proxy
     *   angesprochen hat.
     * - Der Serverwert `HTTPS` kommt vom eigenen Webserver, nicht von außen, und
     *   bleibt deshalb ohne Prüfung gültig.
     */
    private function isHttpsRequest(Request $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        $serverParams = $request->getServerParams();

        $remoteAddress = trim((string) ($serverParams['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress !== '' && ClientIpResolver::isTrustedProxy($remoteAddress)) {
            $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
            $firstProto = strtolower(trim((string) explode(',', $forwardedProto)[0]));
            if ($firstProto === 'https') {
                return true;
            }
        }

        $httpsServerValue = strtolower(trim((string) ($serverParams['HTTPS'] ?? '')));
        return $httpsServerValue !== '' && $httpsServerValue !== 'off';
    }
}
