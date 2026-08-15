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

    /**
     * Setzt die MySQL-Session auf die benannte Zeitzone. Ein fixer Offset waere der Offset des
     * aktuellen Laufs und wuerde nach einem Wechsel zwischen Sommer- und Winterzeit auch auf
     * historische TIMESTAMP-Werte angewendet - diese kaemen dann um eine Stunde verschoben zurueck.
     *
     * CONVERT_TZ liefert NULL, wenn die MySQL-Zeitzonentabellen nicht geladen sind; in dem Fall
     * bleibt der Offset als Rueckfallebene. Die Pruefung laeuft in der Anweisung selbst, damit sie
     * ohne Leserecht auf die mysql-Systemtabellen und ohne zusaetzliche Abfrage auskommt.
     *
     * Beide Werte stammen aus kontrollierten Quellen (Whitelist aus timezone_identifiers_list bzw.
     * DateTimeImmutable::format('P')) und sind damit nicht frei setzbar.
     */
    public static function databaseTimezoneInitCommand(): string
    {
        $namedTimezone = self::resolveAppTimezone();

        return sprintf(
            "SET time_zone = IF(CONVERT_TZ('2000-01-01 00:00:00', 'UTC', '%s') IS NULL, '%s', '%s')",
            $namedTimezone,
            self::resolveDatabaseTimezoneOffset(),
            $namedTimezone
        );
    }

    /**
     * PDO-Optionen fuer die Datenbankverbindung. Die Zeitzone wird als Init-Command gesetzt, weil
     * dieser bei jedem Verbindungsaufbau - auch nach einem Reconnect - erneut laeuft.
     *
     * @return array<int, string>
     */
    public static function databaseConnectionOptions(): array
    {
        // PHP 8.4 hat die PDO::MYSQL_ATTR_*-Konstanten durch Pdo\Mysql abgeloest.
        if (class_exists('Pdo\\Mysql')) {
            return [\Pdo\Mysql::ATTR_INIT_COMMAND => self::databaseTimezoneInitCommand()];
        }

        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            return [\PDO::MYSQL_ATTR_INIT_COMMAND => self::databaseTimezoneInitCommand()];
        }

        return [];
    }
}
