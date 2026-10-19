<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die vier nullbaren Pflichtspalten der Mail-Strecke und des Finanzjournals.
 *
 * 20261019120000 hat zwölf Spalten auf `NOT NULL` gesetzt, die kein Schreibweg
 * je leer lässt. Diese vier blieben damals bewusst aussen vor: Sie stehen in
 * Tabellen, die über Jahre gewachsen sind, und ob dort im Bestand Nullwerte
 * liegen, entscheidet nicht die Anwendung, sondern der Verlauf. Geprüft und
 * entschieden ist das jetzt - sie ziehen nach.
 *
 * - `mail_queue.mail_type`: MailQueueService setzt den Typ auf jedem der sechs
 *   Einstellwege. Ein Eintrag ohne Typ käme in keine Zustellung und in keine
 *   Auswertung, zählte in der Warteschlange aber mit.
 * - `mail_queue.delivery_status`: trägt seit 20260420110000 die Vorgabe
 *   `pending`; die bleibt, nur der Nullwert fällt weg.
 * - `mail_delivery_events.source_channel`: MailDeliveryIngest setzt den Kanal,
 *   bevor er in den Idempotenzschlüssel eingeht - ohne ihn fielen zwei
 *   Ereignisse über verschiedene Wege zu einem zusammen.
 * - `finance_revisions.action`: FinanceJournalService::write() nimmt die Aktion
 *   als `string`, nicht als `?string`. Ein Journaleintrag ohne Aktion sagt
 *   nicht, was geschehen ist, und ist als Nachweis wertlos.
 *
 * Wie in 20261019120000 stehen **alle Prüfungen vor der ersten Verengung**. Ein
 * Bestand mit Nullwerten bricht den Lauf damit ab, bevor irgendeine Spalte
 * angefasst wurde, und die Meldung nennt alle betroffenen Spalten auf einmal
 * statt den Betreiber durch einen Abbruch je Spalte zu schicken.
 */
final class RequireValuesInGrownColumns extends AbstractMigration
{
    /**
     * Tabelle, Spalte, Phinx-Typ und die Angaben, die eine Verengung mitführen
     * muss - `changeColumn()` schreibt die Spalte komplett neu, was hier fehlt,
     * fällt weg. Die Vorgabe von `delivery_status` steht deshalb mit dabei.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private const REQUIRED = [
        ['finance_revisions', 'action', 'enum', ['values' => ['create', 'update', 'reverse', 'lock']]],
        ['mail_delivery_events', 'source_channel', 'enum', ['values' => ['dsn', 'webhook']]],
        ['mail_queue', 'mail_type', 'enum', ['values' => [
            'newsletter',
            'invitation',
            'password_reset',
            'registration_reminder',
            'notification',
        ]]],
        ['mail_queue', 'delivery_status', 'enum', [
            'values' => ['pending', 'accepted', 'delivered', 'bounced', 'complained', 'skipped'],
            'default' => 'pending',
        ]],
    ];

    public function up(): void
    {
        $offenders = [];

        foreach (self::REQUIRED as [$table, $column]) {
            $open = (int) ($this->fetchRow(sprintf(
                'SELECT COUNT(*) AS open FROM %s WHERE %s IS NULL',
                $table,
                $column
            ))['open'] ?? 0);

            if ($open > 0) {
                $offenders[] = sprintf('%s.%s: %d Zeile(n)', $table, $column, $open);
            }
        }

        if ($offenders !== []) {
            throw new RuntimeException(sprintf(
                "Diese Spalten sollen Pflicht werden, tragen im Bestand aber Nullwerte:\n%s\n"
                    . 'Diese Zeilen zuerst füllen oder entfernen - sonst setzt MySQL beim Verengen '
                    . 'den Nullwert des Typs ein und die Zeilen sind still verfälscht.',
                implode("\n", $offenders)
            ));
        }

        foreach (self::REQUIRED as [$table, $column, $type, $options]) {
            $this->table($table)
                ->changeColumn($column, $type, $options + ['null' => false])
                ->update();
        }
    }

    public function down(): void
    {
        // Eine Spalte wieder zu öffnen nimmt niemandem einen Wert - die Zeilen
        // bleiben, wie sie sind. Deshalb keine Zählabfrage davor.
        foreach (self::REQUIRED as [$table, $column, $type, $options]) {
            $this->table($table)
                ->changeColumn($column, $type, $options + ['null' => true, 'default' => null])
                ->update();
        }
    }
}
