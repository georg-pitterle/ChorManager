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
use Slim\Psr7\Factory\StreamFactory;

class HtmlFormCsrfInjectorMiddleware implements MiddlewareInterface
{
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

        $response = $handler->handle($request);

        if (!$this->isRewritableHtml($response)) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '' || stripos($body, '<form') === false) {
            return $response;
        }

        $token = Csrf::ensureToken();

        // Öffnungsschild und Inhalt werden getrennt erfasst. Der frühere Ausdruck lief
        // mit `(?:(?!<\/form>).)*?` je Zeichen durch eine Vorausschau und sprengte ab
        // rund 24 KB Formularinhalt den PCRE-Stapelspeicher: `preg_replace_callback`
        // lieferte dann `null`, die Antwort ging unverändert hinaus und jede Absendung
        // dieses Formulars wurde anschließend mit 403 abgewiesen - lautlos und nur bei
        // großen Formularen. Die Rollenverwaltung liegt mit knapp 19 KB je Formular
        // dicht unter dieser Grenze und trägt selbst kein `_csrf`-Feld.
        $updatedBody = preg_replace_callback(
            '/(<form\b[^>]*>)(.*?)<\/form>/is',
            static function (array $matches) use ($token): string {
                $openingTag = $matches[1];
                $formContent = $matches[2];

                // Das Leerzeichen vor dem Attributnamen ist Absicht: `\bmethod` würde
                // auch auf `data-method="post"` eines GET-Formulars passen und den
                // Token dort in die URL-Abfragezeichenfolge schreiben. Dasselbe gilt
                // für `data-name="_csrf"`, das sonst eine nötige Einfügung unterdrückt.
                if (!preg_match('/\smethod\s*=\s*(["\'])post\1/i', $openingTag)) {
                    return $matches[0];
                }

                if (preg_match('/\sname\s*=\s*(["\'])_csrf\1/i', $formContent)) {
                    return $matches[0];
                }

                // Zusammensetzen statt zweitem `preg_replace`: der Token stünde dort in
                // der Ersetzungszeichenkette, in der `$` und `\` Sonderbedeutung haben.
                return $openingTag
                    . '<input type="hidden" name="_csrf" value="' . $token . '">'
                    . $formContent
                    . '</form>';
            },
            $body
        );

        if ($updatedBody === null) {
            // Ohne diese Zeile ist der Ausfall von außen nicht zu erkennen: Die Antwort
            // geht ohne Token hinaus und jede Absendung des Formulars endet danach mit
            // 403 - ununterscheidbar von einem echten Angriff.
            $this->logger->error('CSRF form injection failed.', [
                'event' => 'security.csrf.form_injection_failed',
                'reason' => preg_last_error_msg(),
            ]);

            return $response;
        }

        if ($updatedBody === $body) {
            return $response;
        }

        $stream = (new StreamFactory())->createStream($updatedBody);
        $response = $response->withBody($stream);

        // Das eingefügte Feld verlängert den Rumpf. Eine bereits gesetzte Längenangabe
        // zeigte danach auf die alte, kürzere Länge - der Browser schnitte die Seite dort ab.
        if ($response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) strlen($updatedBody));
        }

        return $response;
    }

    /**
     * Entscheidet, ob der Rumpf überhaupt eingelesen und umgeschrieben werden darf.
     *
     * Eine fehlende Typangabe bedeutet hier "Seite": `Slim\Views\Twig::render()`
     * schreibt nur in den Rumpf und setzt keinen `Content-Type`, deshalb kommt die
     * überwiegende Mehrheit der gerenderten Seiten ohne Kopfzeile an. Diesen Fall zu
     * überspringen würde jedem Formular der Anwendung den Token nehmen. Ausgeschlossen
     * werden stattdessen die Antworten, die erkennbar keine Seite sind: ein gesetzter
     * Nicht-HTML-Typ und jede Auslieferung mit `Content-Disposition` - dort ist der
     * Rumpf eine Datei, die sonst nur zum Verwerfen vollständig in den Speicher ginge.
     */
    private function isRewritableHtml(Response $response): bool
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return false;
        }

        return !$response->hasHeader('Content-Disposition');
    }
}
