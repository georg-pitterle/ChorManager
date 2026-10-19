<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pflichtspalten werden `NOT NULL`.
 *
 * Phinx legt eine Spalte ohne `'null' => false` nullbar an. Dadurch sind
 * Spalten nullbar geblieben, die kein Schreibweg je leer lässt - und bei vier
 * von ihnen hängt ein `UNIQUE`-Index daran, der mit einem Nullwert nicht mehr
 * greift: MySQL vergleicht `NULL` nie als gleich, zwei Zeilen mit Nullwert
 * verletzen den Index also nicht. Die Zusicherung lag damit allein in der
 * Anwendung.
 *
 * Offen stand konkret:
 *
 * - `event_registrations (event_id, user_id)` - zwei Anmeldungen derselben
 *   Person zum selben Termin. `status` kommt mit, weil eine Anmeldung ohne
 *   Antwort in keiner der drei Auswertungen auftaucht, in den Zählungen aber
 *   mitläuft.
 * - `budget_categories (fiscal_year_start, finance_group_id, type)` - zwei
 *   gleiche Haushaltskategorien im selben Jahr. Beide Schreibwege in
 *   BudgetController weisen eine fehlende Gruppe schon heute ab.
 * - `newsletter_recipient_sources.source_type` - die Spiegeltabelle
 *   `newsletter_template_recipient_sources` hat dieselbe Spalte seit
 *   20261004090300 als `NOT NULL`. Dass die eine nullbar blieb, war ein
 *   Versehen in derselben Migration, keine Entscheidung.
 * - `calendar_subscription_tokens.token_hash` - 20260901122000 hat bereits
 *   dafür gesorgt, dass keine Zeile ohne Hash zurückbleibt, und bewacht das
 *   mit einer Prüfung. Die Spalte selbst blieb nullbar; das zieht hier nach.
 * - `invitation_tokens (user_id, selector, token_hash, expires_at)` und
 *   `finance_accounts (name, type)` - ein Einladungstoken ohne seinen Wert ist
 *   wertlos, ein Konto ohne Namen und Art taucht in keiner Übersicht auf.
 *
 * Nicht dabei sind die nullbaren Spalten der Mail-Strecke
 * (`mail_queue.mail_type`, `mail_queue.delivery_status`,
 * `mail_delivery_events.source_channel`) und `finance_revisions.action`: Sie
 * stammen aus Tabellen, die über Jahre gewachsen sind, und ob dort im Bestand
 * Nullwerte liegen, entscheidet nicht die Anwendung, sondern der Verlauf. Die
 * gehören einzeln geprüft, nicht im Vorbeigehen verengt.
 *
 * **Alle Prüfungen stehen vor der ersten Verengung**, nicht je Spalte davor.
 * Ein Bestand mit Nullwerten bricht den Lauf damit ab, bevor irgendeine Spalte
 * angefasst wurde - und die Meldung nennt alle betroffenen Spalten auf einmal,
 * statt den Betreiber durch einen Abbruch je Spalte zu schicken.
 */
final class RequireValuesInMandatoryColumns extends AbstractMigration
{
    /**
     * Tabelle, Spalte, Phinx-Typ und die Angaben, die eine Verengung mitführen
     * muss - `changeColumn()` schreibt die Spalte komplett neu, was hier fehlt,
     * fällt weg.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private const REQUIRED = [
        ['budget_categories', 'finance_group_id', 'integer', []],
        ['calendar_subscription_tokens', 'token_hash', 'char', ['limit' => 64]],
        ['event_registrations', 'event_id', 'integer', []],
        ['event_registrations', 'user_id', 'integer', []],
        ['event_registrations', 'status', 'enum', ['values' => ['yes', 'no', 'maybe']]],
        ['finance_accounts', 'name', 'string', ['limit' => 100]],
        ['finance_accounts', 'type', 'enum', ['values' => ['cash', 'bank']]],
        ['invitation_tokens', 'user_id', 'integer', ['signed' => true]],
        ['invitation_tokens', 'selector', 'string', ['limit' => 64]],
        ['invitation_tokens', 'token_hash', 'string', ['limit' => 255]],
        ['invitation_tokens', 'expires_at', 'datetime', []],
        ['newsletter_recipient_sources', 'source_type', 'enum', ['values' => ['event_attendees']]],
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
