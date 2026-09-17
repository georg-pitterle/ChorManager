<?php

declare(strict_types=1);

namespace App\Util;

final class InputValidator
{
    /**
     * Ein Formularfeld als Text, oder ein leerer Text, wenn nichts Brauchbares
     * ankam.
     *
     * Der Browser schickt `email=x` als Zeichenkette, `email[]=x` aber als
     * Array - und beides landet gleichberechtigt in `getParsedBody()`. Wer den
     * Wert ungeprüft an eine Funktion mit Typangabe weiterreicht, bekommt dort
     * keinen Formularfehler, sondern einen TypeError: Statusseite 500 samt
     * Stapelverlauf, an den offenen Endpunkten ohne Anmeldung auslösbar.
     *
     * Bewusst ohne `(string)`-Umwandlung: Aus einem Array würde sonst "Array"
     * und aus einem Wahrheitswert "1" - Werte, die nie jemand eingegeben hat
     * und die eine Prüfung hinterher fälschlich bestehen könnten.
     */
    public static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Validate and normalize an email address.
     * Returns null if invalid, otherwise returns normalized email.
     */
    public static function validateEmail(mixed $email): ?string
    {
        $email = trim(self::asString($email));
        if ($email === '') {
            return null;
        }

        $email = strtolower($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Validate and normalize a string input (trim and empty check).
     * Returns null if empty after trim, otherwise returns trimmed string.
     */
    public static function validateRequired(mixed $value, int $maxLength = 0): ?string
    {
        $value = trim(self::asString($value));
        if ($value === '') {
            return null;
        }

        if ($maxLength > 0 && mb_strlen($value, 'UTF-8') > $maxLength) {
            return null;
        }

        return $value;
    }

    /**
     * Validate an integer ID (must be > 0).
     * Returns the ID or null if invalid.
     */
    public static function validateId(?int $value): ?int
    {
        if ($value === null || $value <= 0) {
            return null;
        }

        return $value;
    }
}
