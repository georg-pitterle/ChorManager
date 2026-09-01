<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entfernt drei Einzelindizes, die seit 20260825120100 bzw. 20260826123000
 * nichts mehr leisten.
 *
 * Die drei Quellen-Tabellen bekamen ihren Index auf die Elternspalte, als das
 * noch der einzige war. Inzwischen trägt jede zusätzlich einen eindeutigen
 * Dreier-Index, der mit derselben Spalte beginnt - und ein Index deckt jede
 * führende Teilmenge seiner Spalten mit ab. Der Einzelindex beantwortet damit
 * keine Abfrage mehr, die der Dreier nicht auch beantwortet; er kostet nur bei
 * jedem Schreibvorgang Pflege und Platz.
 *
 * Auch der Fremdschlüssel auf die Elternspalte bleibt gedeckt: MySQL verlangt
 * dafür einen Index, in dem die Spalte an erster Stelle steht - das leistet der
 * Dreier-Index.
 */
final class DropRedundantSourceTableIndexes extends AbstractMigration
{
    /**
     * Tabelle => [Elternspalte, Einzelindex, deckender Dreier-Index]
     */
    private const TARGETS = [
        'event_audience_sources' => [
            'event_id',
            'event_id',
            'uq_event_audience_source',
        ],
        'newsletter_recipient_sources' => [
            'newsletter_id',
            'newsletter_id',
            'uq_newsletter_recipient_source',
        ],
        'newsletter_template_recipient_sources' => [
            'template_id',
            'template_id',
            'uq_newsletter_template_recipient_source',
        ],
    ];

    public function up(): void
    {
        // Prüfung vor dem Entfernen: Fehlt der deckende Index, hinge der
        // Fremdschlüssel nach dem DROP an keinem Index mehr. MySQL wiese das
        // zwar ab, aber erst mitten im Lauf - und die vorherigen Tabellen wären
        // schon geändert.
        foreach (self::TARGETS as $table => [$parentColumn, $singleIndex, $coveringIndex]) {
            if (!$this->coversParentColumn($table, $coveringIndex, $parentColumn)) {
                throw new RuntimeException(sprintf(
                    'Index %s auf %s führt %s nicht an erster Stelle. Der Einzelindex %s bleibt deshalb stehen.',
                    $coveringIndex,
                    $table,
                    $parentColumn,
                    $singleIndex
                ));
            }
        }

        foreach (self::TARGETS as $table => [, $singleIndex]) {
            $this->table($table)
                ->removeIndexByName($singleIndex)
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [$parentColumn, $singleIndex]) {
            $this->table($table)
                ->addIndex([$parentColumn], ['name' => $singleIndex])
                ->update();
        }
    }

    /**
     * Prüft, ob der genannte Index existiert und die Elternspalte an erster
     * Stelle führt.
     */
    private function coversParentColumn(string $table, string $indexName, string $parentColumn): bool
    {
        $row = $this->fetchRow(sprintf(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '%s'
               AND INDEX_NAME = '%s'
               AND SEQ_IN_INDEX = 1",
            $table,
            $indexName
        ));

        return ($row['COLUMN_NAME'] ?? null) === $parentColumn;
    }
}
