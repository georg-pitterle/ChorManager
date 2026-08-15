<?php

declare(strict_types=1);

namespace App\Util;

final class InputValidator
{
    /**
     * Validate and normalize an email address.
     * Returns null if invalid, otherwise returns normalized email.
     */
    public static function validateEmail(?string $email): ?string
    {
        $email = trim((string) $email);
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
    public static function validateRequired(?string $value, int $maxLength = 0): ?string
    {
        $value = trim((string) $value);
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
