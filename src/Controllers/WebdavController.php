<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\AttachmentResponseFactory;
use App\Services\WebdavAccessService;
use App\Services\WebdavNode;
use App\Services\WebdavTreeService;
use App\Services\WebdavXmlBuilder;
use App\Util\AppUrlResolver;
use DOMDocument;
use DOMElement;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Der Noten-Ordner, den eine Noten-App auf dem Tablet einhängen kann.
 *
 * Umgesetzt ist der lesende Ausschnitt von RFC 4918: OPTIONS, PROPFIND mit
 * Tiefe 0 und 1, HEAD und GET. Alles Schreibende antwortet 403 - ein Klient
 * hängt den Ordner daraufhin schreibgeschützt ein. Bewusst ohne sabre/dav: Das
 * bringt einen eigenen HTTP-Stapel neben PSR-7 mit, und für vier lesende
 * Methoden wäre die Brücke dorthin mehr Fläche als der Ausschnitt selbst.
 *
 * Angemeldet wird sich über HTTP-Basic mit einem persönlichen Token
 * (WebdavAccessService), nicht über die Sitzung. Der Zugriff baut auch keine
 * auf: Sonst hinge an einem eingehängten Ordner unbemerkt eine vollständige
 * Anmeldung mit allen Rechten der Person.
 */
class WebdavController
{
    public const BASE_PATH = '/webdav';

    private const REALM = 'ChorManager';

    /** Was ein Klient hier tun darf - der Rest der Methoden wird abgewiesen. */
    private const ALLOWED_METHODS = 'OPTIONS, PROPFIND, HEAD, GET';

    private WebdavAccessService $access;
    private WebdavTreeService $tree;
    private WebdavXmlBuilder $xml;
    private AttachmentResponseFactory $attachments;

    /**
     * Ohne Logger: Protokolliert wird, was eine Antwort verdient - der
     * abgewiesene Zugang, und der steht in WebdavAccessService. Ein Mount
     * erzeugt für eine einzige Ansicht ein Dutzend Anfragen; sie hier
     * mitzuschreiben hieße, das Protokoll mit Rauschen zu füllen.
     */
    public function __construct(
        WebdavAccessService $access,
        WebdavTreeService $tree,
        WebdavXmlBuilder $xml,
        AttachmentResponseFactory $attachments
    ) {
        $this->access = $access;
        $this->tree = $tree;
        $this->xml = $xml;
        $this->attachments = $attachments;
    }

    /**
     * Die Adresse, die in der Noten-App einzutragen ist.
     *
     * Über AppUrlResolver und nicht selbst zusammengesetzt. Zwei Gründe:
     *
     *  - `Uri::getAuthority()` lieferte hinter dem Proxy
     *    `https://chor.example.org:80/webdav/` - Schema aus
     *    `X-Forwarded-Proto`, Port aus `SERVER_PORT` des internen Servers. Das
     *    nimmt kein Klient an.
     *  - Der `Host`-Kopf allein taugt auch nicht: Er ist frei wählbar. Der
     *    Resolver zieht deshalb `APP_URL` vor und wertet Weiterleitungsköpfe
     *    nur von einem vertrauten Proxy aus - dieselbe Grundlage, auf der die
     *    Links in Einladungen und Passwort-Mails stehen.
     *
     * Eine Adresse, die dauerhaft in einer App auf einem Tablet steht, gehört
     * erst recht nicht aus einer einzelnen Anfrage abgeleitet.
     */
    public static function baseUrl(Request $request): string
    {
        return AppUrlResolver::resolveBaseUrl($request) . self::BASE_PATH . '/';
    }

    public function handle(Request $request, Response $response): Response
    {
        $user = $this->authenticate($request);
        if (!$user instanceof User) {
            return $this->unauthorized($response);
        }

        $userId = (int) $user->id;
        $segments = $this->segmentsFromRequest($request);
        $prefix = $this->nextcloudPrefix($segments);
        $path = implode('/', array_slice($segments, count($prefix)));

        return match (strtoupper($request->getMethod())) {
            'OPTIONS' => $this->options($response),
            'PROPFIND' => $this->propfind($request, $response, $userId, $path, $prefix),
            'GET', 'HEAD' => $this->get($request, $response, $userId, $path),
            default => $this->readOnly($response),
        };
    }

    private function options(Response $response): Response
    {
        return $response
            ->withHeader('DAV', '1')
            ->withHeader('MS-Author-Via', 'DAV')
            ->withHeader('Allow', self::ALLOWED_METHODS)
            ->withHeader('Content-Length', '0');
    }

    /**
     * @param list<string> $prefix Wegstrecke, die der Klient vor den Pfad gesetzt hat
     */
    private function propfind(
        Request $request,
        Response $response,
        int $userId,
        string $path,
        array $prefix = []
    ): Response {
        $depth = $this->depth($request);

        // RFC 4918, Abschnitt 9.1: Ein Server darf unbegrenzte Tiefe ablehnen,
        // und das ist hier die richtige Antwort - sonst zöge ein Klient beim
        // Einhängen die Metadaten aller Projekte in einer einzigen Anfrage.
        if ($depth === 'infinity') {
            return $this->xmlResponse($response->withStatus(403), $this->xml->error('propfind-finite-depth'));
        }

        $node = $this->tree->resolve($userId, $path);
        if (!$node instanceof WebdavNode) {
            return $response->withStatus(404);
        }

        $resources = [['href' => $this->href($node, $prefix), 'node' => $node]];

        if ($depth === '1') {
            foreach ($this->tree->children($userId, $node) as $child) {
                $resources[] = ['href' => $this->href($child, $prefix), 'node' => $child];
            }
        }

        [$properties, $namesOnly] = $this->requestedProperties($request);

        return $this->xmlResponse(
            $response->withStatus(207),
            $this->xml->multiStatus($resources, $properties, $namesOnly)
        );
    }

    private function get(Request $request, Response $response, int $userId, string $path): Response
    {
        $node = $this->tree->resolve($userId, $path);
        if (!$node instanceof WebdavNode) {
            return $response->withStatus(404);
        }

        if ($node->isCollection) {
            return $response->withStatus(405)->withHeader('Allow', self::ALLOWED_METHODS);
        }

        $attachment = $this->tree->loadFile($node);
        if ($attachment === null) {
            return $response->withStatus(404);
        }

        return $this->attachments->inline($response, $attachment, $request->getHeaderLine('Range'));
    }

    private function readOnly(Response $response): Response
    {
        return $response->withStatus(403)->withHeader('Allow', self::ALLOWED_METHODS);
    }

    private function unauthorized(Response $response): Response
    {
        return $response
            ->withStatus(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="' . self::REALM . '", charset="UTF-8"');
    }

    private function authenticate(Request $request): ?User
    {
        $credentials = $this->credentials($request);
        if ($credentials === null) {
            return null;
        }

        [$email, $token] = $credentials;

        return $this->access->authenticate($email, $token);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function credentials(Request $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            $server = $request->getServerParams();

            // Manche FastCGI-Aufbauten reichen den Kopf nur unter diesen Namen
            // durch; ohne den Rückfall käme jede Anfrage als "nicht angemeldet"
            // an, obwohl der Klient Zugangsdaten geschickt hat.
            $header = (string) ($server['HTTP_AUTHORIZATION']
                ?? $server['REDIRECT_HTTP_AUTHORIZATION']
                ?? '');
        }

        if (stripos($header, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$email, $token] = explode(':', $decoded, 2);

        return [$email, $token];
    }

    /**
     * Der Pfad kommt aus der Adresse und nicht aus den Routen-Argumenten: Slim
     * reicht die unentschlüsselt weiter, und ein Liedtitel mit Leerzeichen oder
     * Umlaut steht dort prozentkodiert.
     */
    private function segmentsFromRequest(Request $request): array
    {
        $raw = $request->getUri()->getPath();

        if (str_starts_with($raw, self::BASE_PATH)) {
            $raw = substr($raw, strlen(self::BASE_PATH));
        }

        $segments = [];
        foreach (explode('/', trim($raw, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded !== '') {
                $segments[] = $decoded;
            }
        }

        return $segments;
    }

    /**
     * Entfernt die Nextcloud-Wegstrecke, wenn ein Klient sie anhängt.
     *
     * MobileSheets bietet WebDAV nur als "Nextcloud-Server" an und hängt an die
     * eingegebene Basis-Adresse `remote.php/dav/files/<benutzer>/` an - bei
     * Nextcloud liegen die Dateien dort. Ohne diesen Ausgleich landete jede
     * Anfrage der App auf einer 404, und die App meldete ihren nichtssagenden
     * "WebdavError 6". Belegt durch die Protokollzeile vom 24.09.2026, 15:07 UTC:
     *
     *   PROPFIND /webdav/remote.php/dav/files/<mail>/ 404 MobileSheets/2 CFNetwork/...
     *
     * Der Benutzer im Pfad wird verworfen, nicht geprüft: Wer etwas sieht,
     * entscheidet allein das Token aus der Basic-Auth-Anmeldung. Ein anderer
     * Name im Pfad verschafft damit keinen anderen Bestand, er wäre nur eine
     * zweite Wahrheit neben der ersten.
     *
     * `remote.php/webdav` ist die ältere ownCloud-Form derselben Sache.
     *
     * @param list<string> $segments
     * @return list<string>
     */
    private function nextcloudPrefix(array $segments): array
    {
        if (($segments[0] ?? '') !== 'remote.php') {
            return [];
        }

        if (($segments[1] ?? '') === 'dav' && ($segments[2] ?? '') === 'files') {
            // Der Benutzername ist bei Nextcloud Teil des Pfades; fehlt er, ist
            // die Wurzel gemeint.
            return array_values(array_slice($segments, 0, min(4, count($segments))));
        }

        if (($segments[1] ?? '') === 'webdav') {
            return array_values(array_slice($segments, 0, 2));
        }

        return [];
    }

    /**
     * Die Adresse eines Knotens, so wie der Klient sie angefragt hat.
     *
     * `$prefix` kommt wieder mit hinein, obwohl er für uns bedeutungslos ist:
     * Ein Klient ordnet die Einträge einer 207-Antwort über ihre href zu und
     * sucht darin seine eigene Anfrage wieder. Antwortete der Server auf
     * `/webdav/remote.php/dav/files/x/` mit Einträgen unter `/webdav/`, fände er
     * sich selbst nicht und hielte die Antwort für fremd.
     *
     * @param list<string> $prefix
     */
    private function href(WebdavNode $node, array $prefix = []): string
    {
        $segments = $node->path === '' ? [] : explode('/', $node->path);
        $encoded = implode('/', array_map('rawurlencode', [...$prefix, ...$segments]));

        $href = self::BASE_PATH . ($encoded === '' ? '' : '/' . $encoded);

        return $node->isCollection ? $href . '/' : $href;
    }

    /**
     * Fehlt der Kopf, gilt nach RFC 4918 unbegrenzte Tiefe. Hier gilt stattdessen
     * Tiefe 1: Der einzige Klient ohne `Depth` ist einer, der den Ordner
     * durchblättert, und dem mit einer 403 nicht geholfen wäre.
     */
    private function depth(Request $request): string
    {
        $depth = strtolower(trim($request->getHeaderLine('Depth')));

        return match ($depth) {
            '0' => '0',
            '1' => '1',
            'infinity' => 'infinity',
            default => '1',
        };
    }

    /**
     * Wertet den Anfragekörper eines PROPFIND aus.
     *
     * @return array{0: list<string>|null, 1: bool} Eigenschaftsnamen (null = allprop) und propname-Kennzeichen
     */
    private function requestedProperties(Request $request): array
    {
        $body = (string) $request->getBody();
        if (trim($body) === '') {
            return [null, false];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        // LIBXML_NONET verbietet Netzzugriffe beim Auflösen; bewusst ohne
        // LIBXML_NOENT, das Entitäten erst einsetzen würde.
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || !$document->documentElement instanceof DOMElement) {
            return [null, false];
        }

        if ($document->getElementsByTagNameNS('DAV:', 'propname')->length > 0) {
            return [null, true];
        }

        $propNodes = $document->getElementsByTagNameNS('DAV:', 'prop');
        if ($propNodes->length === 0) {
            return [null, false];
        }

        $properties = [];
        foreach ($propNodes->item(0)->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $properties[] = $child->localName ?? '';
            }
        }

        $properties = array_values(array_filter($properties, static fn(string $name): bool => $name !== ''));

        return $properties === [] ? [null, false] : [$properties, false];
    }

    private function xmlResponse(Response $response, string $xml): Response
    {
        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Length', (string) strlen($xml));
    }
}
