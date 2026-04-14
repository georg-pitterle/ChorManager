<?php

declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;
use DateTimeZone;

class Timezone
{
    private const DEFAULT_TIMEZONE = 'Europe/Vienna';

    public static function resolveAppTimezone(): string
    {
        $configuredTimezone = EnvHelper::read('APP_TIMEZONE', EnvHelper::read('TZ', self::DEFAULT_TIMEZONE));

        if (in_array($configuredTimezone, timezone_identifiers_list(), true)) {
            return $configuredTimezone;
        }

        return self::DEFAULT_TIMEZONE;
    }

    public static function resolveDatabaseTimezoneOffset(): string
    {
        $timezone = new DateTimeZone(self::resolveAppTimezone());
        $now = new DateTimeImmutable('now', $timezone);

        return $now->format('P');
    }
}
