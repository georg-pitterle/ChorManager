<?php

declare(strict_types=1);

namespace App\Util;

use Carbon\Carbon;

/**
 * Welche Frist für das Logo einer Vereinbarung gilt.
 *
 * Braucht die Vereinbarung das Logo für den Druck, zählt die Druck-Frist des
 * Projekts. Steht dort keine, gilt ersatzweise die Online-Frist - spätestens
 * dann wird das Logo ohnehin gebraucht. Ohne jedes Datum bleibt die Art
 * bekannt, das Datum leer ("ohne Frist").
 */
final class LogoDeadline
{
    public const PRINT = 'print';
    public const ONLINE = 'online';

    /**
     * @return array{date: ?Carbon, kind: string}|null null, wenn kein Logo gebraucht wird
     */
    public static function for(bool $print, bool $online, ?Carbon $printDeadline, ?Carbon $onlineDeadline): ?array
    {
        if (!$print && !$online) {
            return null;
        }

        if ($print && $printDeadline !== null) {
            return ['date' => $printDeadline, 'kind' => self::PRINT];
        }

        if ($onlineDeadline !== null) {
            return ['date' => $onlineDeadline, 'kind' => self::ONLINE];
        }

        return ['date' => null, 'kind' => $print ? self::PRINT : self::ONLINE];
    }
}
