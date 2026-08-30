<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ob Aufgaben im abonnierten Kalender auftauchen sollen, lässt sich nicht für
 * alle gleich beantworten: Wer den Kalender als Terminplan liest, will die
 * Aufgaben dazwischen nicht sehen; wer ihn als Tagesübersicht nutzt, gerade
 * doch. Deshalb entscheidet jede Person im eigenen Profil.
 *
 * Die Einstellung liegt an `users` und nicht in einer eigenen Tabelle - mit
 * `last_project_id` steht dort schon eine persönliche Einstellung, und eine
 * Zeile je Person gäbe es so oder so.
 */
final class AddCalendarTaskSettingsToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            // Voreinstellung `combined`: Der bestehende Abo-Link liefert dann
            // ohne Zutun auch die Aufgaben. Wer das nicht will, stellt auf `none`.
            ->addColumn('calendar_task_feed', 'enum', [
                'values' => ['none', 'combined', 'separate'],
                'default' => 'combined',
                'null' => false,
                'after' => 'last_project_id',
            ])
            // `event` liefert ganztägige Termine, `todo` echte Aufgaben. Ganztägig
            // ist die Voreinstellung, weil Apple und Google VTODO in abonnierten
            // Kalendern schlicht verwerfen.
            ->addColumn('calendar_task_format', 'enum', [
                'values' => ['event', 'todo'],
                'default' => 'event',
                'null' => false,
                'after' => 'calendar_task_feed',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('calendar_task_feed')
            ->removeColumn('calendar_task_format')
            ->update();
    }
}
