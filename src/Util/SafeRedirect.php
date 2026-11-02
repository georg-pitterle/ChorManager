<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Validates post-login redirect targets to prevent open redirects.
 * Only same-origin relative paths are allowed.
 */
final class SafeRedirect
{
    /**
     * Grenze für das Ziel, nicht für die Sicherheit: Geprüft wird die Form. Sie muss
     * auch eine Anmeldeanfrage angeschlossener Anwendungen fassen - die von Nextcloud
     * ist rund 570 Zeichen lang - und bleibt URL-kodiert in `/login?redirect=` unter
     * der Grenze von 8 KB, die nginx für eine Header-Zeile setzt.
     */
    public const MAX_LENGTH = 2048;

    public static function sanitize(?string $target): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }

        if (strlen($target) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return null;
        }

        if (str_contains($target, '\\') || str_contains($target, '://')) {
            return null;
        }

        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        return $target;
    }
}
