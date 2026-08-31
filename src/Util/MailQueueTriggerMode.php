<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\AppSetting;

/**
 * Die Betriebsart des Mailversands - gelesen aus `mailqueue_trigger_mode`.
 *
 * Drei Middlewares hängen daran (Warteschlange, Anmelde-Erinnerung,
 * Benachrichtigungs-Erinnerung) und lasen den Wert vorher jede für sich. Dabei
 * behandelte nur die Warteschlange einen leeren Eintrag wie einen fehlenden; die
 * beiden Erinnerungen lasen ihn als unbekannte Betriebsart und stellten ihre
 * Arbeit dauerhaft und lautlos ein. Über die Oberfläche kann der Wert nicht leer
 * werden - `AppSettingController` normalisiert ihn -, über einen direkten
 * Datenbankzugriff schon.
 */
final class MailQueueTriggerMode
{
    public const CRON = 'cron';
    public const HYBRID = 'hybrid';
    public const OPPORTUNISTIC = 'opportunistic';

    /**
     * Die eingestellte Betriebsart; ein fehlender oder leerer Eintrag bedeutet
     * `hybrid` - dieselbe Vorgabe, die auch die Einstellungsmaske anzeigt.
     */
    public static function current(): string
    {
        $stored = AppSetting::query()
            ->where('setting_key', 'mailqueue_trigger_mode')
            ->value('setting_value');

        if (!is_scalar($stored) || (string) $stored === '') {
            return self::HYBRID;
        }

        return (string) $stored;
    }

    /**
     * Darf im Anfrageweg nebenbei gearbeitet werden? Bei reinem Cron-Betrieb
     * nicht - dort erledigen die Kommandos in `bin/` die Arbeit.
     */
    public static function allowsOpportunisticWork(): bool
    {
        return in_array(self::current(), [self::HYBRID, self::OPPORTUNISTIC], true);
    }
}
