<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEventAudienceSources extends AbstractMigration
{
    public function up(): void
    {
        $this->table('event_audience_sources')
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'role', 'user', 'voice_group']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['event_id'])
            ->addForeignKey(
                'event_id',
                'events',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();

        $this->execute(
            "INSERT INTO event_audience_sources (event_id, source_type, reference_id)
             SELECT id, 'project_members', project_id
             FROM events
             WHERE project_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - der kam im Betrieb dazu und steht
        // nirgends sonst. Ein Rückbau nähme ihn ohne Nachfrage mit.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM event_audience_sources'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: event_audience_sources enthält %d Zeile(n), die mit der Tabelle '
                    . 'unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('event_audience_sources')->drop()->save();
    }
}
