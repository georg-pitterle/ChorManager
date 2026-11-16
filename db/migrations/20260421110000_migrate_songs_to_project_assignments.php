<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Schreibt die Zuordnung aus songs.project_id in project_song_assignments um.
 *
 * ## Diese Migration ist der Boden der Rückbau-Kette
 *
 * Ihr `down()` bricht unbedingt ab, und `phinx rollback` arbeitet die Kette
 * streng von hinten ab. Hier endet sie also immer - die zehn Migrationen mit
 * älterem Zeitstempel (20260314130000_initial bis 20260421100000) sind über
 * den Rückbau nicht mehr zu erreichen, und ihr `down()` ist damit toter Code.
 * Nachgestellt in Lauf 76: Der Rückbau auf null lief auf einer
 * Wegwerf-Datenbank genau bis hierher.
 *
 * Das hat eine Folge, die man kennen muss, wenn man dort unten liest: Diese
 * zehn `down()` werden **nicht mehr nachgepflegt**. Am deutlichsten sichtbar
 * ist das am `down()` von 20260421100000 - es wirft drei gefüllte Tabellen weg
 * und zählt davor nur `songs.project_id IS NULL`, was die Vorgabe in
 * `/phinx-migration` sonst nicht durchgehen lässt. Eine Zählabfrage
 * nachzutragen hieße, eine Prüfung zu schreiben, die nie läuft; genau diesen
 * toten Code hält `DestructiveStepNeedsGuardTest` mit seiner Ausnahmeliste
 * auch anderswo heraus. So entschieden in der Nacharbeit zu Lauf 76.
 *
 * Tragend ist dabei allein, dass dieses `down()` abbricht. Wer es jemals
 * umkehrbar macht, macht die zehn `down()` darunter wieder erreichbar - und
 * dann fehlen dort Prüfungen. `Tests\Unit\Migrations\RollbackFloorTest` hält
 * die Zusicherung fest, damit das nicht unbemerkt passiert.
 */
final class MigrateSongsToProjectAssignments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'INSERT INTO project_song_assignments (project_id, song_id, note, created_at)
            SELECT project_id, id AS song_id, NULL AS note, created_at FROM songs WHERE project_id IS NOT NULL
            ON DUPLICATE KEY UPDATE note = note'
        );
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Irreversible migration: project_song_assignments backfill cannot be safely rolled back.'
        );
    }
}
