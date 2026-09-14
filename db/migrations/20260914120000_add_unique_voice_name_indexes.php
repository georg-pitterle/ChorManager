<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUniqueVoiceNameIndexes extends AbstractMigration
{
    public const VOICE_GROUP_INDEX = 'voice_groups_name_unique';
    public const SUB_VOICE_INDEX = 'sub_voices_group_name_unique';
    public const REDUNDANT_SUB_VOICE_INDEX = 'voice_group_id';

    public function up(): void
    {
        // Die Besetzungsansicht der Auswertungen gruppiert nach dem *Namen* der
        // Stimmgruppe (ProjectQuery::getProjectMembersGroupedByVoice() nimmt ihn als
        // Array-Schlüssel). Gibt es "Sopran" zweimal, landen die Mitglieder beider
        // Gruppen stillschweigend unter einer Überschrift, und die zweite Gruppe
        // verschwindet aus der Anzeige, ohne dass irgendwo ein Fehler auftaucht.
        // Dasselbe gilt für zwei gleichnamige Teilstimmen innerhalb einer Stimmgruppe.
        //
        // Zwei gleichnamige Stimmgruppen sind ein Vertipper, kein gültiger Zustand.
        // Der Index fängt ihn dort ab, wo er entsteht, statt die Anzeige nachträglich
        // um ihn herum zu bauen.
        $this->guardAgainstDuplicates(
            'SELECT COUNT(*) AS duplicates FROM (
                SELECT name FROM voice_groups GROUP BY name HAVING COUNT(*) > 1
            ) AS doubled',
            'Es gibt %d gleichnamige Stimmgruppe(n). Diese zuerst umbenennen oder '
                . 'zusammenlegen, sonst kann der eindeutige Index nicht gesetzt werden.'
        );

        $this->guardAgainstDuplicates(
            'SELECT COUNT(*) AS duplicates FROM (
                SELECT voice_group_id, name FROM sub_voices
                GROUP BY voice_group_id, name HAVING COUNT(*) > 1
            ) AS doubled',
            'Es gibt %d gleichnamige Teilstimme(n) innerhalb derselben Stimmgruppe. '
                . 'Diese zuerst umbenennen oder zusammenlegen, sonst kann der eindeutige '
                . 'Index nicht gesetzt werden.'
        );

        $this->table('voice_groups')
            ->addIndex(['name'], ['unique' => true, 'name' => self::VOICE_GROUP_INDEX])
            ->update();

        // Teilstimmen tragen ihren Namen nur innerhalb ihrer Stimmgruppe eindeutig:
        // "Sopran 1" und "Alt 1" wären sonst kein Problem, "1" in beiden Gruppen aber
        // schon. Deshalb der zusammengesetzte Index und nicht einer auf name allein.
        $this->table('sub_voices')
            ->addIndex(['voice_group_id', 'name'], ['unique' => true, 'name' => self::SUB_VOICE_INDEX])
            ->update();

        // Der Einzelindex auf voice_group_id beantwortet ab jetzt keine Abfrage mehr,
        // die der neue Zweier-Index nicht auch beantwortet - ein Index deckt jede
        // führende Teilmenge seiner Spalten mit ab. Er kostet nur noch Pflege bei
        // jedem Schreibvorgang. Derselbe Schritt wie in 20260901120000.
        //
        // Der Fremdschlüssel sub_voices_ibfk_1 bleibt gedeckt: MySQL verlangt dafür
        // einen Index, in dem die Spalte an erster Stelle steht, und genau so ist der
        // Zweier-Index aufgebaut.
        $this->table('sub_voices')
            ->removeIndexByName(self::REDUNDANT_SUB_VOICE_INDEX)
            ->update();
    }

    public function down(): void
    {
        // Zuerst der Einzelindex zurück, erst danach der Zweier-Index weg: sonst
        // stünde der Fremdschlüssel auf voice_group_id kurzzeitig ohne deckenden
        // Index da, und MySQL lehnt das Entfernen ab.
        $this->table('sub_voices')
            ->addIndex(['voice_group_id'], ['name' => self::REDUNDANT_SUB_VOICE_INDEX])
            ->update();

        $this->table('sub_voices')
            ->removeIndexByName(self::SUB_VOICE_INDEX)
            ->update();

        $this->table('voice_groups')
            ->removeIndexByName(self::VOICE_GROUP_INDEX)
            ->update();
    }

    /**
     * Bricht ab, bevor der Index gesetzt wird.
     *
     * Die Prüfung steht bewusst vor der Schemaänderung: liefe sie danach, hätte
     * MySQL den Index-Aufbau bereits mit einer eigenen, wortkargen Fehlermeldung
     * abgelehnt, und der Betreiber wüsste nicht, welche Namen er anfassen muss.
     */
    private function guardAgainstDuplicates(string $countQuery, string $messageTemplate): void
    {
        $duplicates = (int) ($this->fetchRow($countQuery)['duplicates'] ?? 0);

        if ($duplicates > 0) {
            throw new RuntimeException(sprintf($messageTemplate, $duplicates));
        }
    }
}
