<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zielgruppen-Filter: ein Filter besteht aus Bedingungen je Kategorie.
 * Innerhalb einer Kategorie genügt ein Wert, zwischen Kategorien müssen alle
 * zutreffen. reference_id hat keinen Fremdschlüssel, weil die Bedingung je
 * nach Kategorie auf eine andere Tabelle zeigt; ein gelöschter Bezug trifft
 * danach schlicht niemanden.
 */
final class CreateAudienceFilterTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('audience_filters')
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->create();

        $this->table('audience_filter_conditions')
            ->addColumn('audience_filter_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('category', 'enum', [
                'values' => ['role', 'voice_group', 'sub_voice', 'project', 'user'],
                'null' => false,
            ])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['audience_filter_id', 'category', 'reference_id'], [
                'unique' => true,
                'name' => 'uniq_audience_filter_conditions',
            ])
            ->addIndex(['category', 'reference_id'])
            ->addForeignKey('audience_filter_id', 'audience_filters', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_audience_filter_conditions_filter',
            ])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabellen sind erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - der kam im Betrieb dazu und steht
        // nirgends sonst. Ein Rückbau nähme ihn ohne Nachfrage mit.
        $filled = [];

        foreach (['audience_filter_conditions', 'audience_filters'] as $table) {
            $rowsLeft = (int) ($this->fetchRow(
                sprintf('SELECT COUNT(*) AS rows_left FROM %s', $table)
            )['rows_left'] ?? 0);

            if ($rowsLeft > 0) {
                $filled[] = sprintf('%s: %d Zeile(n)', $table, $rowsLeft);
            }
        }

        if ($filled !== []) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %s. Diese Zeilen wären mit den Tabellen '
                    . 'unwiederbringlich weg - zuerst bewusst selbst entfernen.',
                implode(', ', $filled)
            ));
        }

        $this->table('audience_filter_conditions')->drop()->save();
        $this->table('audience_filters')->drop()->save();
    }
}
