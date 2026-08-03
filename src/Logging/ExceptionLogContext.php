<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Baut den Log-Kontext fuer eine gefangene Exception.
 *
 * Illuminate\Database\QueryException::formatMessage() haengt die SQL-Bindings
 * an getMessage() an. Ein Log-Aufruf mit 'exception' => $e protokolliert diese
 * Bindings damit ungefiltert - an den beiden Aufrufstellen, die diese Klasse
 * nutzen, stehen dort ein verschluesseltes IMAP-Zugangsdatum bzw. ein
 * bcrypt-Passwort-Hash. Fuer eine QueryException wird deshalb eine sanitisierte
 * Ersatzdarstellung gebaut (Exception-Klasse, SQL-Statement, Treiberfehler -
 * nie die Bindings). Fuer alles andere bleibt die bisherige Konvention
 * ('exception' => $e) unveraendert.
 */
final class ExceptionLogContext
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Throwable $e): array
    {
        $queryException = self::findQueryException($e);

        if ($queryException === null) {
            return ['exception' => $e];
        }

        return [
            'exception_class' => $queryException::class,
            'sql' => $queryException->getSql(),
            'driver_error' => self::driverError($queryException),
        ];
    }

    /**
     * Sucht die QueryException auch in der Ursachenkette.
     *
     * Monolog laeuft beim Normalisieren ueber getPrevious() und gibt jede
     * Nachricht aus. Eine in eine andere Exception verpackte QueryException
     * wuerde ihre Bindings sonst ueber die Kette doch wieder ins Log tragen.
     */
    private static function findQueryException(Throwable $e): ?QueryException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException) {
                return $current;
            }
        }

        return null;
    }

    private static function driverError(QueryException $e): string
    {
        $previous = $e->getPrevious();

        // $previous->getMessage() is the raw PDO/driver message and, unlike
        // QueryException::getMessage(), was never rebuilt with the bindings
        // substituted into the SQL.
        return $previous !== null ? $previous->getMessage() : 'unknown driver error';
    }
}
