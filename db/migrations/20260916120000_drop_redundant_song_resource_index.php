<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entfernt song_resources_song_id_idx.
 *
 * 20260429220000 hat der Tabelle beide Indizes auf einmal mitgegeben: einen auf
 * song_id allein und einen auf (song_id, resource_type, title). Der zweite
 * beginnt mit derselben Spalte, und ein Index deckt jede führende Teilmenge
 * seiner Spalten mit ab - der Einzelindex beantwortet damit seit dem ersten Tag
 * keine Abfrage, die der Dreier nicht auch beantwortet. Er kostet nur bei jedem
 * Schreibvorgang Pflege und Platz.
 *
 * Song::linkResources() fragt genau in der Form, für die der Dreier gebaut ist:
 * song_id gleich, resource_type gleich, sortiert nach title. Der Einzelindex
 * wäre dafür auch dann die schlechtere Wahl, wenn MySQL ihn nähme.
 *
 * Derselbe Schritt wie in 20260901120000 für die drei Quellen-Tabellen und in
 * 20260914120000 für sub_voices.voice_group_id.
 */
final class DropRedundantSongResourceIndex extends AbstractMigration
{
    private const TABLE = 'song_resources';
    private const PARENT_COLUMN = 'song_id';
    private const REDUNDANT_INDEX = 'song_resources_song_id_idx';
    private const COVERING_INDEX = 'song_resources_song_type_title_idx';

    public function up(): void
    {
        // Prüfung vor dem Entfernen: Der Fremdschlüssel song_resources_song_fk
        // braucht einen Index, in dem song_id an erster Stelle steht. Fehlt der
        // deckende Index, wiese MySQL das DROP zwar ab - aber mit einer
        // wortkargen Meldung, die nicht sagt, welcher Index gemeint ist.
        if (!$this->coversParentColumn(self::COVERING_INDEX)) {
            throw new RuntimeException(sprintf(
                'Index %s auf %s führt %s nicht an erster Stelle. Der Einzelindex %s bleibt deshalb stehen.',
                self::COVERING_INDEX,
                self::TABLE,
                self::PARENT_COLUMN,
                self::REDUNDANT_INDEX
            ));
        }

        $this->table(self::TABLE)
            ->removeIndexByName(self::REDUNDANT_INDEX)
            ->update();
    }

    public function down(): void
    {
        $this->table(self::TABLE)
            ->addIndex([self::PARENT_COLUMN], ['name' => self::REDUNDANT_INDEX])
            ->update();
    }

    /**
     * Prüft, ob der genannte Index existiert und die Elternspalte an erster
     * Stelle führt.
     */
    private function coversParentColumn(string $indexName): bool
    {
        $row = $this->fetchRow(sprintf(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '%s'
               AND INDEX_NAME = '%s'
               AND SEQ_IN_INDEX = 1",
            self::TABLE,
            $indexName
        ));

        return ($row['COLUMN_NAME'] ?? null) === self::PARENT_COLUMN;
    }
}
