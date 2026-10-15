<?php

declare(strict_types=1);

namespace App\Handlers;

use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Handlers\ErrorHandler;

/**
 * Slims Fehler-Handler mit strukturierter Protokollzeile.
 *
 * Der Standard-Handler schreibt jede Ausnahme als ERROR mit einem Textblock aus
 * Typ, Meldung und Stapelverlauf, ohne `event`. Ein 405 für einen GET auf eine
 * reine POST-Route sah damit aus wie ein Absturz, und in der täglichen
 * Log-Analyse ließ sich die Zeile nach keinem Ereignis gruppieren.
 *
 * Fehler der aufrufenden Seite (4xx) sind jetzt WARNING mit eigenem Ereignis,
 * alles andere bleibt ERROR und trägt die Ausnahme im Schlüssel `exception`.
 * Methode, Pfad und Person liefert der Request-Kontext in `extra`.
 */
final class LoggingErrorHandler extends ErrorHandler
{
    protected function writeToErrorLog(): void
    {
        $exception = $this->exception;

        if ($exception instanceof HttpMethodNotAllowedException) {
            $this->logger->warning('Request method not allowed for this route.', [
                'event' => 'http.method_not_allowed',
                'status' => $this->statusCode,
                'allowed_methods' => $exception->getAllowedMethods(),
            ]);

            return;
        }

        if ($exception instanceof HttpException && $this->statusCode < 500) {
            $this->logger->warning('Request rejected with a client error.', [
                'event' => 'http.client_error',
                'status' => $this->statusCode,
                'exception_class' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $this->logger->error('Unhandled exception while processing the request.', [
            'event' => 'app.unhandled_exception',
            'status' => $this->statusCode,
            'exception' => $exception,
        ]);
    }
}
