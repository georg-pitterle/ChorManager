<?php

declare(strict_types=1);

namespace App\Util;

final class AppEnvironment
{
    public static function current(): string
    {
        return strtolower(EnvHelper::read('APP_ENV', 'production'));
    }

    public static function isDebugEnabled(): bool
    {
        return in_array(self::current(), ['development', 'dev', 'local', 'test'], true);
    }

    public static function isProduction(): bool
    {
        return self::current() === 'production';
    }
}
