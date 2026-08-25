<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Doppelte Zielgruppen-Zeilen verhindert bisher nur die Anwendung:
 * EventAudienceService::setSources löscht und legt in einer Transaktion neu an,
 * der Newsletter-Weg macht es entsprechend. Schreibt ein Import, ein Skript oder
 * ein künftiger Codepfad direkt in die Tabellen, entstehen Duplikate - und
 * damit doppelt gezählte Empfänger in Versand und Auswertung.
 *
 * Der eindeutige Schlüssel zieht die Zusicherung in die Datenbank. Vorhandene
 * Duplikate werden vorher zusammengeführt: Fachlich sind sie dieselbe Quelle,
 * es bleibt die jeweils älteste Zeile stehen.
 */
final class AddUniqueIndexToAudienceSources extends AbstractMigration
{
    /**
     * Tabelle => [Elternspalte, Indexname]
     */
    private const TARGETS = [
        'event_audience_sources' => ['event_id', 'uq_event_audience_source'],
        'newsletter_recipient_sources' => ['newsletter_id', 'uq_newsletter_recipient_source'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$parentColumn, $indexName]) {
            $this->removeDuplicates($table, $parentColumn);

            $this->table($table)
                ->addIndex([$parentColumn, 'source_type', 'reference_id'], [
                    'unique' => true,
                    'name' => $indexName,
                ])
                ->update();
        }
    }

    public function down(): void
    {
        // Die zusammengeführten Duplikate kommen nicht zurück - sie waren
        // fachlich schon vorher dieselbe Quelle.
        foreach (self::TARGETS as $table => [, $indexName]) {
            $this->table($table)
                ->removeIndexByName($indexName)
                ->update();
        }
    }

    /**
     * Behält je Kombination die kleinste id und entfernt den Rest.
     */
    private function removeDuplicates(string $table, string $parentColumn): void
    {
        $this->execute(sprintf(
            'DELETE duplicate
             FROM %1$s duplicate
             JOIN (
                 SELECT MIN(id) AS keep_id, %2$s AS parent_id, source_type, reference_id
                 FROM %1$s
                 GROUP BY %2$s, source_type, reference_id
             ) survivor
               ON survivor.parent_id = duplicate.%2$s
              AND survivor.source_type = duplicate.source_type
              AND survivor.reference_id = duplicate.reference_id
             WHERE duplicate.id > survivor.keep_id',
            $table,
            $parentColumn
        ));
    }
}
