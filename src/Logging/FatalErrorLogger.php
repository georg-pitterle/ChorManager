<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\LoggerInterface;

/**
 * Schreibt einen Fatal Error als strukturierte Logzeile.
 *
 * Speicherlimit, Zeitüberschreitung, ein Parse-Fehler in einer spät geladenen
 * Datei: Das sind keine Ausnahmen. Slims ErrorMiddleware sieht sie nie, die
 * Anfrage endet mit einem leeren 500, und PHP-FPM schreibt die Meldung allenfalls
 * als Klartext neben die JSON-Zeilen. So blieb das Repertoire im Live-Betrieb
 * ohne jeden Logeintrag liegen, als es an 100 MB Liedanhängen ins Speicherlimit
 * lief.
 *
 * Monologs eigener ErrorHandler kann das auch, setzt aber keinen `event`-Key;
 * den verlangt der Logging-Standard für jede Zeile.
 */
final class FatalErrorLogger
{
    private const FATAL_TYPES = [
        E_ERROR => 'E_ERROR',
        E_PARSE => 'E_PARSE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_USER_ERROR => 'E_USER_ERROR',
    ];

    /**
     * Luft über dem aktuellen Verbrauch, damit Formatieren und Schreiben der
     * Logzeile nach einem erschöpften Speicherlimit nicht selbst daran scheitern.
     */
    private const HEADROOM_BYTES = 16 * 1024 * 1024;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function register(): void
    {
        register_shutdown_function(fn () => $this->handleShutdown(error_get_last()));
    }

    /**
     * @param array{type: int, message: string, file: string, line: int}|null $error
     */
    public function handleShutdown(?array $error): void
    {
        if ($error === null || !isset(self::FATAL_TYPES[$error['type']])) {
            return;
        }

        $this->ensureHeadroom();

        $this->logger->critical('PHP fatal error.', [
            'event' => 'php.fatal_error',
            'error_type' => self::FATAL_TYPES[$error['type']],
            'error_message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
    }

    /**
     * Hebt das Limit an, senkt es aber nie: Ein großzügig gesetztes Limit bleibt,
     * ein unbegrenztes (-1) ebenso.
     */
    private function ensureHeadroom(): void
    {
        $current = trim((string) ini_get('memory_limit'));
        if ($current === '-1') {
            return;
        }

        $needed = memory_get_usage(true) + self::HEADROOM_BYTES;
        if (self::toBytes($current) < $needed) {
            ini_set('memory_limit', (string) $needed);
        }
    }

    private static function toBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
