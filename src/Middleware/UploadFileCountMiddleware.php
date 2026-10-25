<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\RefererTarget;
use App\Util\RequestFormat;
use App\Util\SessionConfig;
use App\Util\UploadFileCountGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Lehnt eine Anfrage ab, bei der PHP Dateien verworfen hat (siehe UploadFileCountGuard).
 *
 * Abgelehnt wird die ganze Anfrage, nicht nur der Überhang: Wer 60 Stimmen
 * hochlädt und 50 gespeichert findet, merkt das Fehlen der letzten zehn erst,
 * wenn jemand sie sucht.
 */
class UploadFileCountMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?bool $exceeded = null
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!($this->exceeded ?? UploadFileCountGuard::exceeded())) {
            return $handler->handle($request);
        }

        $this->logger->warning('Upload rejected: too many files in one request', [
            'event' => 'upload.too_many_files',
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'max_files' => UploadFileCountGuard::MAX_FILES,
        ]);

        if (RequestFormat::expectsJson($request)) {
            $response = new SlimResponse(413);
            $response->getBody()->write((string) json_encode(
                ['ok' => false, 'error' => UploadFileCountGuard::message()],
                JSON_UNESCAPED_UNICODE
            ));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        if (SessionConfig::ensureStarted()) {
            $_SESSION['error'] = UploadFileCountGuard::message();
        }

        return (new SlimResponse(302))->withHeader('Location', $this->backTarget($request));
    }

    /**
     * Zurück auf die Seite, von der das Formular kam - oder auf die Startseite.
     *
     * Geprüft wird in `RefererTarget`, gemeinsam mit der CsrfMiddleware. Hier stand
     * eine eigene, schwächere Abschrift: Sie ließ allein `/` am Anfang und `//`
     * entscheiden und übersah damit `/\fremder.host`. Browser lesen den
     * Rückwärtsschrägstrich einer Adresse wie einen Schrägstrich, im `Location`-Kopf
     * wurde daraus also `//fremder.host` - ein offener Weiterleiter. Und erreichbar
     * war er: Diese Middleware hängt bewusst außerhalb der CSRF-Prüfung, ein Formular
     * fremder Herkunft kommt hier an.
     */
    private function backTarget(Request $request): string
    {
        return RefererTarget::resolve($request) ?? '/';
    }
}
