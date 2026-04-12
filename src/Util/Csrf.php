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
