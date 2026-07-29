<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Session storage location.
 *
 * PHP writes session files into the container's writable layer by default, so
 * every image update or container recreate discards them and logs every user
 * out. SESSION_SAVE_PATH points PHP at a directory that is backed by a named
 * volume in the production stack.
 */
class SessionConfig
{
    public const ENV_KEY = 'SESSION_SAVE_PATH';

    /**
     * Apply the configured session save path.
     *
     * An unset variable or an unusable path keeps the PHP default: a broken
     * override must not take sessions - and with them every login - down.
     *
     * @return bool True when the save path was applied.
     */
    public static function applySavePath(): bool
    {
        $configured = EnvHelper::readRaw(self::ENV_KEY);
        if ($configured === null) {
            return false;
        }

        $path = trim($configured);
        if ($path === '') {
            return false;
        }

        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            return false;
        }

        return ini_set('session.save_path', $path) !== false;
    }
}
