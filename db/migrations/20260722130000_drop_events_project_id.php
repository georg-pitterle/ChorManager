<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropEventsProjectId extends AbstractMigration
{
    /**
     * Findet Termine, deren Projekt die vorangehende Migration 20260722120000
     * nicht nach event_audience_sources umgeschrieben hat - etwa weil zwischen
     * beiden Läufen ein Termin direkt in der Datenbank angelegt wurde.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * RepairFinanceAccountOpeningData::REPAIR_SQL.
     */
    public const ORPHANED_EVENTS_SQL = <<<'SQL'
        SELECT COUNT(*) AS orphaned
        FROM events e
        LEFT JOIN event_audience_sources s
          ON s.event_id = e.id
         AND s.source_type = 'project_members'
         AND s.reference_id = e.project_id
        WHERE e.project_id IS NOT NULL
          AND s.event_id IS NULL
        SQL;

    public function up(): void
    {
        // Prüfung vor dem destruktiven Schritt: Nach dem DROP COLUMN ließe sich
        // nicht mehr feststellen, welchem Projekt ein Termin gehörte - der Wert
        // steht dann nirgendwo mehr. Gleiches Muster wie in
        // 20260421120000_drop_songs_project_id.
        $orphaned = (int) ($this->fetchRow(self::ORPHANED_EVENTS_SQL)['orphaned'] ?? 0);

        if ($orphaned > 0) {
            throw new \RuntimeException(sprintf(
                'Cannot drop events.project_id: %d Termin(e) haben keine passende Zielgruppen-Zeile. '
                    . 'Diese zuerst nachtragen, sonst verlieren sie ihr Projekt.',
                $orphaned
            ));
        }

        $foreignKey = $this->fetchRow(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'events'
               AND COLUMN_NAME = 'project_id'
               AND REFERENCED_TABLE_NAME = 'projects'
             LIMIT 1"
        );

        if ($foreignKey && !empty($foreignKey['CONSTRAINT_NAME'])) {
            $this->execute('ALTER TABLE events DROP FOREIGN KEY ' . $foreignKey['CONSTRAINT_NAME']);
        }

        $this->execute('ALTER TABLE events DROP COLUMN project_id');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE events ADD COLUMN project_id int(11) DEFAULT NULL');
        $this->execute('ALTER TABLE events ADD INDEX project_id (project_id)');
        $this->execute('ALTER TABLE events ADD CONSTRAINT events_project_id_fk
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');

        // Die Zuordnung zurückholen, solange es sie noch gibt: Die Quellentabelle
        // fällt erst im down() der vorangehenden Migration 20260722120000, also
        // nach diesem Schritt. Ohne die Rückschreibung käme die Spalte leer zurück
        // und jeder Termin verlöre sein Projekt. Gleiches Muster wie in
        // 20260513220000 für newsletters.event_id.
        //
        // Ein Termin kann seit der Umstellung mehrere Projektquellen haben, in die
        // eine Spalte passt aber nur eine - MIN() macht die Auswahl wenigstens
        // deterministisch statt beliebig. Der JOIN auf projects filtert Quellen
        // auf gelöschte Projekte aus: reference_id ist polymorph und hat deshalb
        // keinen Fremdschlüssel, der neue Constraint auf events aber schon.
        $this->execute(
            "UPDATE events e
               INNER JOIN (
                   SELECT s.event_id, MIN(s.reference_id) AS project_id
                   FROM event_audience_sources s
                   INNER JOIN projects p ON p.id = s.reference_id
                   WHERE s.source_type = 'project_members'
                   GROUP BY s.event_id
               ) src ON src.event_id = e.id
             SET e.project_id = src.project_id"
        );
    }
}
