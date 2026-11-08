<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Oidc\OidcEndpoints;
use App\Services\RememberLoginRestoreService;
use App\Util\Csrf;
use App\Util\RefererTarget;
use App\Util\RequestFormat;
use App\Util\SessionConfig;
use App\Util\SessionExpiredSignal;
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
        // Scaleway Topics and Events, ausgewiesen über die SNS-Signatur und das
        // konfigurierte Topic (SnsMessageVerifier).
        '/mail/delivery/scaleway',
        // Der Token-Endpunkt des OIDC-Providers. Absender ist die
        // angeschlossene Anwendung von Server zu Server, kein Browser: Es gibt
        // keine Sitzung und damit keinen Token, den sie mitschicken könnte.
        // Ausgewiesen wird sich mit Client-Secret und code_verifier, und
        // TokenController prüft beides, bevor er die Nutzlast anfasst - ohne
        // diese Ausnahme hätte die Middleware jede Anmeldung mit 403 abgewiesen.
        OidcEndpoints::TOKEN,
    ];

    /**
     * Der Noten-Ordner. Er spricht WebDAV, und dessen lesende Methode PROPFIND
     * fällt nicht unter die oben freigegebenen GET/HEAD/OPTIONS - ohne diese
     * Ausnahme hätte die Middleware jedes Auflisten eines Ordners mit 403
     * abgewiesen, noch bevor der Controller die Zugangsdaten gesehen hat.
     *
     * Hier wird auf das Präfix geprüft und nicht auf den ganzen Pfad: Unter
     * `/webdav` liegt ein ganzer Baum, dessen Pfade aus Projekt-, Lied- und
     * Dateinamen bestehen. Schützenswert ist dort nichts - WebdavController
     * wertet ausschließlich den Basic-Auth-Nachweis aus und nie die Sitzung.
     *
     * `/wopi`: Collabora speichert von Server zu Server und hat weder Sitzung
     * noch CSRF-Token. WopiController weist es über das Zugangstoken aus.
     *
     * @var list<string>
     */
    private const EXEMPT_PREFIXES = [
        '/webdav',
        '/wopi',
    ];

    private LoggerInterface $logger;

    /**
     * Optional, damit die zahlreichen Tests, die nur die Token-Prüfung brauchen,
     * die Middleware weiter ohne Datenbankdienste bauen können. Verdrahtet wird
     * er in Dependencies.php.
     */
    private ?RememberLoginRestoreService $rememberRestore;

    public function __construct(
        LoggerInterface $logger = new NullLogger(),
        ?RememberLoginRestoreService $rememberRestore = null
    ) {
        $this->logger = $logger;
        $this->rememberRestore = $rememberRestore;
    }

    /**
     * Die Ausnahmepfade werden geprüft, bevor eine Sitzung entsteht.
     *
     * Vorher stand `ensureStarted()` samt `ensureToken()` darüber, und damit
     * legte jede Anfrage an einen Ausnahmepfad eine Sitzung samt CSRF-Token an -
     * für Absender, die beides nie vorzeigen können und auch nicht sollen: Der
     * Noten-Ordner weist sich über Basic-Auth aus, Collabora über sein
     * Zugangstoken, die Mailprovider über Signatur bzw. Ingest-Token, die
     * angeschlossene Anwendung am Token-Endpunkt über Client-Secret und
     * code_verifier. Keiner dieser Controller liest die Sitzung.
     *
     * Für jeden anderen Pfad bleibt es beim bisherigen Ablauf: Die Sitzung
     * entsteht hier, und sie muss es - die Wiederherstellung per Remember-Me
     * weiter unten tauscht die Sitzungskennung, und das geht nur in einer
     * laufenden Sitzung.
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($this->isExemptPath($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        SessionConfig::ensureStarted();

        Csrf::ensureToken();

        return $this->withCurrentToken($this->guard($request, $handler));
    }

    /**
     * Nennt der Seite den Token, der nach der Anfrage gilt.
     *
     * Gelesen wird erst nach dem Handler: Die AuthMiddleware stellt eine
     * verfallene Sitzung per Remember-Me innerhalb derselben Anfrage wieder her
     * und dreht dabei den Token. Genau diesen Wechsel bekam eine offene Seite
     * bisher nie mit - ihr nächster POST lief in "Ungültiger CSRF-Token".
     */
    private function withCurrentToken(Response $response): Response
    {
        if ($this->mayBeStored($response)) {
            return $response;
        }

        $token = $_SESSION[Csrf::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            return $response;
        }

        return $response->withHeader(Csrf::RESPONSE_HEADER, $token);
    }

    /**
     * Darf diese Antwort aufbewahrt werden?
     *
     * Dann bekommt sie den Token nicht. Zwei Wege führen von einer aufbewahrten
     * Antwort zurück:
     *
     * - Ein gemeinsamer Zwischenspeicher - Reverse Proxy, CDN - darf eine
     *   `public`-Antwort samt Kopfzeilen aufbewahren und dem nächsten Abruf
     *   ausliefern. Dann kennt eine fremde Person den Token einer anderen
     *   Sitzung, und genau damit lässt sich die Fälschungs-Sperre aushebeln,
     *   gegen die er schützt. Das deckte diese Prüfung schon seit Lauf 61.
     * - Der Zwischenspeicher des aufrufenden Browsers behält eine
     *   `private`-Antwort mit Dauer genauso samt Kopfzeilen, nur auf dessen
     *   Platte. Zwei Routen weisen sich so aus: die Hilfe-Bilder
     *   (`private, max-age=86400`) und das Logo (`private, max-age=300`). Weil
     *   alles andere `no-store` trägt, lag der Token damit als einziges
     *   überhaupt auf der Platte - und public/js/csrf-token-sync.js übernimmt
     *   ihn aus **jeder** fetch-Antwort in Seite und Formulare. Holte künftig
     *   ein fetch eine dieser Routen und der Browser lieferte sie aus seinem
     *   Zwischenspeicher, überschriebe die Seite ihren gültigen Token mit dem
     *   alten, und der nächste POST endete in "Ungültiger CSRF-Token". Heute
     *   holt kein fetch eine solche Route; die Prüfung nimmt der nächsten
     *   Änderung die Falle weg (Lauf 68).
     *
     * Verloren geht nichts: Was eine Dauer nennt, ist hier ein Bild - kein
     * Formular, das seinen Token nachziehen müsste. Alles andere behält den
     * Kopf, und das ist der Regelfall: Ohne eigene Angabe setzt die
     * SecurityHeadersMiddleware `no-store`, und die hängt außerhalb dieser
     * Middleware - hier steht dann noch gar kein `Cache-Control`.
     */
    private function mayBeStored(Response $response): bool
    {
        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));

        if ($cacheControl === '' || str_contains($cacheControl, 'no-store')) {
            return false;
        }

        if (str_contains($cacheControl, 'public')) {
            return true;
        }

        // Eine Dauer von 0 ist keine Aufbewahrung.
        return (bool) preg_match('/\b(?:s-maxage|max-age)\s*=\s*[1-9]\d*/', $cacheControl);
    }

    private function guard(Request $request, RequestHandler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
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
            // Token fehlt (Formular ohne Feld, Aufruf ohne Header) oder passt nicht zur
            // Sitzung (veraltet, Sitzung erneuert). Zusammen mit `authenticated` lässt
            // sich so eine verfallene Sitzung von einem veralteten Token trennen.
            'reason' => $providedToken === null ? 'token_missing' : 'token_mismatch',
            'authenticated' => isset($_SESSION['user_id']),
        ]);

        // Ein Aufruf per `fetch` kann mit einer Weiterleitung nichts anfangen - er
        // folgt ihr, bekommt die Anmeldeseite als HTML mit Status 200 zurück und
        // scheitert erst beim Auswerten, mit einer Meldung, die nichts mit der
        // abgelaufenen Sitzung zu tun hat.
        $expectsJson = RequestFormat::expectsJson($request);

        // Ist die Sitzung verfallen, während die Seite offen lag, und ist dieser POST die
        // erste Anfrage danach, landete die Person bisher auf der Anmeldeseite - obwohl sie
        // "Eingeloggt bleiben" gewählt hat und jede Abfrage sie still wieder angemeldet
        // hätte. Deshalb wird die Sitzung hier genauso wiederhergestellt wie in der
        // AuthMiddleware.
        //
        // Angenommen wird der POST trotzdem nicht: Sein Token gehört zur verlorenen
        // Sitzung, und ein Token, der nicht zur Sitzung passt, ist genau das, wogegen die
        // Prüfung schützt. Ein fremder Cross-Site-POST kommt hier ohnehin nicht an - das
        // Remember-Me-Cookie ist SameSite=Lax und fehlt ihm.
        if (!isset($_SESSION['user_id']) && $this->rememberRestore?->restoreFromCookie($request) === true) {
            return $this->renewedSessionResponse($request, $expectsJson);
        }

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
            // Derselbe Anlass, derselbe Merker: Ohne Sitzung ist der fehlende
            // Token kein Angriff, sondern eine abgelaufene Sitzung - und dieser
            // Weg ist der häufigere, weil jeder `fetch`-POST die AuthMiddleware
            // gar nicht erst erreicht. Ohne die Markierung hier bliebe deren
            // 401-Antwort auf GET-Aufrufe beschränkt, und die Oberfläche zeigte
            // bei Mitgliederverwaltung, Newsletter und Anmeldungen weiter einen
            // Fehler, der die Ursache verdeckt.
            //
            // Bei angemeldeter Sitzung bleibt es beim schlichten 403: Das ist
            // der Fall, für den die Prüfung da ist, und ein Neuladen würde ihn
            // nur verdecken.
            if (!isset($_SESSION['user_id'])) {
                return SessionExpiredSignal::fill($response);
            }

            $response->getBody()->write((string) json_encode([
                'error' => 'Ungültiger CSRF-Token',
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write('Ungültiger CSRF-Token');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Wohin nach dem Ablauf der Sitzung - mit der Seite als Ziel, auf der das
     * Formular stand. Ohne sicheres Ziel (siehe `RefererTarget`) bleibt es bei der
     * nackten Anmeldeseite.
     */
    private function loginLocation(Request $request): string
    {
        $target = RefererTarget::resolve($request);
        if ($target === null || $target === '/login') {
            return '/login';
        }

        return '/login?redirect=' . rawurlencode($target);
    }

    /**
     * Antwort auf einen POST, für den die Sitzung eben per Remember-Me wieder
     * aufgebaut wurde.
     *
     * `fetch`-Aufrufe bekommen den neuen Token im Kopf (withCurrentToken) und den
     * Hinweis, es noch einmal zu versuchen; public/js/csrf-token-sync.js wiederholt
     * den Aufruf genau einmal. Ein klassisch abgeschicktes Formular lässt sich
     * nicht still wiederholen - es geht angemeldet zurück auf die Seite, von der es
     * kam, mit der Bitte, noch einmal abzusenden.
     */
    private function renewedSessionResponse(Request $request, bool $expectsJson): Response
    {
        $this->logger->info('Session renewed through remember-me on a POST with a stale token.', [
            'event' => 'security.csrf.session_renewed',
        ]);

        if ($expectsJson) {
            $response = new SlimResponse(403);
            $response->getBody()->write((string) json_encode([
                'error' => 'Die Sitzung wurde erneuert. Bitte noch einmal versuchen.',
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader(Csrf::RETRY_HEADER, '1');
        }

        $_SESSION['warning'] = 'Deine Sitzung wurde erneuert. Bitte sende das Formular noch einmal ab.';

        return (new SlimResponse())
            ->withHeader('Location', RefererTarget::resolve($request) ?? '/dashboard')
            ->withStatus(302);
    }

    /**
     * Vergleicht auf ganze Pfade, nicht auf Präfixe: `/mail/delivery/webhook/x` bleibt
     * damit geschützt, ein angehängter Schrägstrich hebt die Ausnahme aber nicht auf.
     */
    private function isExemptPath(string $path): bool
    {
        $normalizedPath = rtrim($path, '/');

        if ($normalizedPath !== '' && in_array($normalizedPath, self::EXEMPT_PATHS, true)) {
            return true;
        }

        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
