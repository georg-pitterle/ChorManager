<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropEventsProjectId extends AbstractMigration
{
    public function up(): void
    {
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
