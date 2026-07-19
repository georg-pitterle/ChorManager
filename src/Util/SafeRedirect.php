<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Validates post-login redirect targets to prevent open redirects.
 * Only same-origin relative paths are allowed.
 */
final class SafeRedirect
{
    public static function sanitize(?string $target): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }

        if (strlen($target) > 512) {
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
