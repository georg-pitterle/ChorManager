<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\StreamFactory;

class HtmlFormCsrfInjectorMiddleware implements MiddlewareInterface
{
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
        if ($body === '' || !str_contains(strtolower($body), '<form')) {
            return $response;
        }

        $token = Csrf::ensureToken();
        $updatedBody = preg_replace_callback(
            '/<form\b(?:(?!<\/form>).)*?<\/form>/is',
            static function (array $matches) use ($token): string {
                $formMarkup = $matches[0];

                // Das Leerzeichen vor dem Attributnamen ist Absicht: `\bmethod` würde
                // auch auf `data-method="post"` eines GET-Formulars passen und den
                // Token dort in die URL-Abfragezeichenfolge schreiben. Dasselbe gilt
                // für `data-name="_csrf"`, das sonst eine nötige Einfügung unterdrückt.
                if (!preg_match('/<form\b[^>]*\smethod\s*=\s*(["\'])post\1/i', $formMarkup)) {
                    return $formMarkup;
                }

                if (preg_match('/\sname\s*=\s*(["\'])_csrf\1/i', $formMarkup)) {
                    return $formMarkup;
                }

                return preg_replace(
                    '/(<form\b[^>]*>)/i',
                    '$1<input type="hidden" name="_csrf" value="' . $token . '">',
                    $formMarkup,
                    1
                ) ?? $formMarkup;
            },
            $body
        );

        if ($updatedBody === null || $updatedBody === $body) {
            return $response;
        }

        $stream = (new StreamFactory())->createStream($updatedBody);
        return $response->withBody($stream);
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
