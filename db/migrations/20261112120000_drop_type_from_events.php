<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entfernt die mitgeschriebene Spalte `events.type`.
 *
 * Der Name der Terminart stand zweimal: in `event_types.name` und zusätzlich
 * als Zeichenkette an jedem Termin. Geschrieben wurde die Spalte beim Anlegen
 * und Bearbeiten eines Termins, beim Umbenennen der Terminart dagegen nicht -
 * sie lief damit auseinander, und die Anwesenheitsseite zeigte deshalb bis Lauf
 * 72 den alten Namen weiter an. Seither liest nur noch der Rückfall in
 * `Event::typeName()` die Spalte; maßgeblich ist die Beziehung.
 *
 * Vor dem Entfernen wird nachgetragen: Ein Termin ohne `event_type_id`, dessen
 * Textfeld den Namen einer bestehenden Terminart nennt, bekommt die Beziehung
 * gesetzt. Das ist keine Annahme, sondern genau der Name, der in der
 * Oberfläche stand - nur eben jetzt an der Stelle, die ihn pflegt.
 *
 * Danach zählt die Prüfung, was ohne Gegenstück bleibt: ein Textfeld, zu dem
 * es keine Terminart gibt. Solche Zeilen verlören ihren Typnamen ersatzlos, und
 * der Lauf bricht dann ab, statt den Namen stumm wegzuwerfen. Von Hand gelöst
 * wird das, indem die fehlende Terminart angelegt und dem Termin zugeordnet
 * wird.
 *
 * Ein veralteter Text neben einer vorhandenen Terminart zählt bewusst nicht
 * mit: Genau dieser Text ist der Fehler, den Lauf 72 behoben hat. Er geht
 * verloren, und das ist die Absicht - der gültige Name steht in
 * `event_types.name`.
 */
final class DropTypeFromEvents extends AbstractMigration
{
    /**
     * Termine, deren Typname nach dem Nachtragen nirgends sonst steht. Als
     * Konstante, damit DestructiveMigrationGuardTest genau diese Anweisung
     * gegen Wegwerf-Tabellen laufen lassen kann.
     */
    public const ORPHANED_TYPE_TEXTS = "SELECT COUNT(*) AS orphaned
          FROM events
         WHERE event_type_id IS NULL
           AND TRIM(COALESCE(type, '')) <> ''";

    public function up(): void
    {
        // Nachtragen, solange die Spalte noch dasteht: Der Text nennt den Namen
        // einer Terminart, die es gibt - dann ist die Beziehung die bessere
        // Fassung desselben Werts.
        $this->execute(
            "UPDATE events e
               INNER JOIN event_types t ON t.name = e.type
                SET e.event_type_id = t.id
              WHERE e.event_type_id IS NULL"
        );

        $orphaned = $this->fetchRow(self::ORPHANED_TYPE_TEXTS);

        if ((int) ($orphaned['orphaned'] ?? 0) > 0) {
            throw new \RuntimeException(
                'Cannot drop events.type: ' . (int) $orphaned['orphaned']
                . ' event(s) carry a type name that matches no row in event_types. '
                . 'Create the missing event type and assign it to those events first.'
            );
        }

        $this->table('events')->removeColumn('type')->update();
    }

    public function down(): void
    {
        // Mit Vorgabe anlegen und sie danach wieder abräumen: Die Spalte war
        // NOT NULL ohne Vorgabe, und ein ALTER ohne Vorgabe scheitert im
        // strikten Modus an den bestehenden Zeilen.
        $this->execute("ALTER TABLE events ADD COLUMN type varchar(255) NOT NULL DEFAULT ''");

        // Den Namen aus der Beziehung zurückschreiben - dieselbe Quelle, aus
        // der ihn `Event::typeName()` heute liest. Ein Termin ohne Terminart
        // bekommt die leere Zeichenkette; einen anderen Wert gab es für ihn
        // nach dem Nachtragen im up() auch vorher nicht.
        $this->execute(
            "UPDATE events e
                LEFT JOIN event_types t ON t.id = e.event_type_id
                SET e.type = COALESCE(t.name, '')"
        );

        $this->execute('ALTER TABLE events ALTER COLUMN type DROP DEFAULT');
    }
}
