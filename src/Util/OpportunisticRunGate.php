<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\AppSetting;
use Carbon\Carbon;

/**
 * Die Wartezeit für Arbeit, die nebenbei im Anfrageweg erledigt wird.
 *
 * Drei Middlewares hängen daran - Mailwarteschlange, Anmelde-Erinnerung,
 * Benachrichtigungs-Erinnerung - und schrieben dieselben vier Schritte je einmal
 * aus: Betriebsart prüfen, letzten Lauf lesen, Wartezeit vergleichen, Merker
 * schreiben. Drei Abschriften derselben Regel heißen drei Stellen, an denen eine
 * Korrektur vergessen werden kann; hier steht sie einmal.
 */
final class OpportunisticRunGate
{
    /**
     * Meldet, ob dieser Aufruf die Arbeit übernehmen darf, und verbucht sie
     * gleich mit.
     *
     * Der Merker wird bewusst **vor** der Arbeit gesetzt und nicht danach:
     * Bricht der Lauf ab, wartet die nächste Anfrage die volle Wartezeit, statt
     * es sofort wieder zu versuchen und jede folgende Anfrage mit demselben
     * Fehler zu belasten.
     *
     * Fehler werden nicht abgefangen - eine nicht erreichbare Datenbank gehört
     * in die Behandlung der aufrufenden Middleware, die allein weiß, ob sie den
     * Ausfall als erwartbares Rauschen oder als Fehler protokolliert.
     *
     * @param string $markerKey                Schlüssel in `app_settings`, unter dem der
     *                                         Zeitpunkt des letzten Laufs steht.
     * @param int    $minimumIntervalSeconds   Mindestabstand zweier Läufe in Sekunden.
     */
    public static function tryClaim(string $markerKey, int $minimumIntervalSeconds): bool
    {
        if (!MailQueueTriggerMode::allowsOpportunisticWork()) {
            return false;
        }

        if (!self::isDue($markerKey, $minimumIntervalSeconds)) {
            return false;
        }

        AppSetting::updateOrCreate(
            ['setting_key' => $markerKey],
            [
                'setting_value' => Carbon::now()->format('Y-m-d H:i:s'),
                'binary_content' => '',
                'mime_type' => 'text/plain',
            ]
        );

        return true;
    }

    /**
     * Ein fehlender oder leerer Merker bedeutet "noch nie gelaufen". Leer kann er
     * über die Oberfläche nicht werden, über einen direkten Datenbankzugriff
     * schon - und als unlesbare Zeitangabe gelesen bliebe die Arbeit dauerhaft
     * und lautlos stehen.
     */
    private static function isDue(string $markerKey, int $minimumIntervalSeconds): bool
    {
        $lastRunRaw = AppSetting::query()
            ->where('setting_key', $markerKey)
            ->value('setting_value');

        if ($lastRunRaw === null || (string) $lastRunRaw === '') {
            return true;
        }

        return !Carbon::parse((string) $lastRunRaw)
            ->addSeconds($minimumIntervalSeconds)
            ->isFuture();
    }
}
