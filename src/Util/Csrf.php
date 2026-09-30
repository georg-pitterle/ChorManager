<?php

declare(strict_types=1);

namespace App\Util;

final class Csrf
{
    public const SESSION_KEY = '_csrf_token';

    public static function ensureToken(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Erneuert den Token und gibt den neuen zurück.
     *
     * Gehört an jede Stelle, die mit `session_regenerate_id(true)` die
     * Sitzungskennung tauscht - Anmeldung, Ersteinrichtung, Wiederherstellung
     * per Remember-Me, Passwortwechsel. Die Kennung wechselt dort, die
     * Sitzungsdaten ziehen aber mit um: Der Token überlebte den Wechsel und
     * blieb derselbe, den die Sitzung schon vor der Anmeldung trug.
     *
     * Wer ihn von vorher kennt - etwa weil er der Sitzung ein eigenes Cookie
     * untergeschoben hat -, kennt ihn danach weiter. Die neue Kennung schickt
     * der Browser bei einer gefälschten Anfrage selbst mit, und der
     * mitgelieferte Token passt; der Schutz wäre für diese Sitzung wirkungslos.
     *
     * Der Preis ist klein und bekannt: Ein Formular, das in einem anderen Tab
     * offen steht, trägt danach einen veralteten Token und wird beim Absenden
     * einmalig abgewiesen.
     */
    public static function rotate(): string
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $providedToken): bool
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        if ($providedToken === null || $providedToken === '') {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $providedToken);
    }
}
