<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Lesbare Größenangabe für Bytes. KB ganzzahlig, ab MB eine Nachkommastelle -
 * so zeigten die Sponsoring-Anhänge Größen schon bisher an.
 */
final class ByteFormatter
{
    private const LARGE_UNITS = ['MB', 'GB', 'TB'];

    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes) . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        $value = $bytes / 1048576;
        $unit = self::LARGE_UNITS[0];
        foreach (array_slice(self::LARGE_UNITS, 1) as $next) {
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit = $next;
        }

        return number_format($value, 1, ',', '.') . ' ' . $unit;
    }
}
