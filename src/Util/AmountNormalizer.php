<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Normalizes user- and machine-supplied money strings into a plain decimal
 * representation with a dot as decimal separator (e.g. "1.234,56" => "1234.56").
 */
class AmountNormalizer
{
    public static function normalize(string $amount): string
    {
        $normalized = preg_replace('/[\s\x{00A0}\']+/u', '', trim($amount)) ?? trim($amount);

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // If both separators exist, treat the rightmost as decimal separator and the other as thousands separator.
            $decimalSep = $lastComma > $lastDot ? ',' : '.';
            $thousandsSep = $decimalSep === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSep, '', $normalized);
            return $decimalSep === ',' ? str_replace(',', '.', $normalized) : $normalized;
        }

        if ($lastComma !== false && substr_count($normalized, ',') > 1) {
            return self::collapseThousandsGrouping($normalized, ',');
        }

        if ($lastComma !== false) {
            return str_replace(',', '.', $normalized);
        }

        if ($lastDot !== false && substr_count($normalized, '.') > 1) {
            return self::collapseThousandsGrouping($normalized, '.');
        }

        return $normalized;
    }

    /**
     * Wie im Finanzmodul: erst das Eingabeformat normalisieren ("1.500,00" =>
     * "1500.00"), dann prüfen. Ohne die Prüfung wird "abc" still zu 0,00 und ein
     * negativer Betrag kommentarlos gespeichert.
     *
     * Ein leeres Feld ist dagegen erlaubt und gilt als 0,00: Bei einer
     * Kontaktanfrage stehen Paket und Betrag meist noch nicht fest, und auch
     * eine Absage muss sich ohne Betrag festhalten lassen.
     */
    public static function normalizeNonNegative(string $amount): ?string
    {
        if ($amount === '') {
            return '0.00';
        }

        $normalized = self::normalize($amount);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return null;
        }

        return $normalized;
    }

    /**
     * Collapses a purely-grouped number (e.g. "1.234.567" or "1,234,567") that uses
     * $separator more than once. A trailing 3-digit group is treated as a thousands
     * group (dropped); any other length is treated as the decimal fraction.
     */
    private static function collapseThousandsGrouping(string $normalized, string $separator): string
    {
        $parts = explode($separator, $normalized);
        $fraction = array_pop($parts);
        $integerPart = implode('', $parts);

        if (strlen($fraction) === 3) {
            return $integerPart . $fraction;
        }

        return $integerPart . '.' . $fraction;
    }
}
