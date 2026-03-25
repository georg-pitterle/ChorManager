<?php

declare(strict_types=1);

namespace App\Util;

class EnvHelper
{
    /**
     * Read an environment variable with fallback chain: $_ENV -> $_SERVER -> getenv()
     *
     * @param string $key The environment variable name
     * @param string $default Default value if not found
     * @return string
     */
    public static function read(string $key, string $default = ''): string
    {
        $value = self::readRaw($key);
        if ($value === null) {
            return $default;
        }

        $value = trim($value);
        return $value !== '' ? $value : $default;
    }

    /**
     * Read a boolean environment variable with fallback chain
     *
     * @param string $key The environment variable name
     * @param bool $default Default value if not found
     * @return bool
     */
    public static function readBool(string $key, bool $default = false): bool
    {
        $value = self::readRaw($key);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return $default;
    }

    /**
     * Read raw environment variable (before trimming/validation)
     *
     * @param string $key The environment variable name
     * @return string|null
     */
    public static function readRaw(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        $value = getenv($key);
        if ($value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
