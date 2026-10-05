<?php

declare(strict_types=1);

namespace App\Util;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Abstand zweier Zeitpunkte nach der Uhr an der Wand statt nach verstrichenen
 * Sekunden.
 *
 * Gebraucht für Vorläufe in Terminserien: "zwei Tage vorher, 19:00" soll bei
 * jedem Termin 19:00 bleiben. In echten Sekunden gerechnet verrutscht der
 * Zeitpunkt um eine Stunde, sobald die Zeitumstellung dazwischen liegt.
 * Gerechnet wird deshalb auf den Ortszeit-Angaben, als wären sie UTC - dort
 * gibt es keine Umstellung.
 */
final class WallClockOffset
{
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * Sekunden, die man auf die Uhrzeit von $from addiert, um die von $to zu erhalten.
     */
    public static function seconds(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return self::asUtc($to)->getTimestamp() - self::asUtc($from)->getTimestamp();
    }

    /**
     * $base um $seconds nach der Uhr verschoben, in der Zeitzone der Anwendung.
     */
    public static function shift(CarbonInterface|string $base, int $seconds): Carbon
    {
        return Carbon::parse(
            self::asUtc($base)->addSeconds($seconds)->format(self::FORMAT),
            date_default_timezone_get()
        );
    }

    private static function asUtc(CarbonInterface|string $value): Carbon
    {
        $local = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return Carbon::parse($local->format(self::FORMAT), 'UTC');
    }
}
