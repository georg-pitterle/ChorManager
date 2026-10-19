<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEventRegistrations extends AbstractMigration
{
    public function up(): void
    {
        $this->table('event_registrations')
            ->addColumn('event_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('status', 'enum', ['values' => ['yes', 'no', 'maybe']])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('updated_by', 'integer', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['event_id', 'user_id'], ['unique' => true])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('updated_by', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('events')
            ->addColumn('registration_enabled', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('registration_deadline', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('registration_reminder_sent_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('attendance_required', 'boolean', ['default' => true, 'null' => false])
            ->save();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - der kam im Betrieb dazu und steht
        // nirgends sonst. Ein Rückbau nähme ihn ohne Nachfrage mit.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM event_registrations'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: event_registrations enthält %d Zeile(n), die mit der Tabelle '
                    . 'unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('event_registrations')->drop()->save();

        $this->table('events')
            ->removeColumn('registration_enabled')
            ->removeColumn('registration_deadline')
            ->removeColumn('registration_reminder_sent_at')
            ->removeColumn('attendance_required')
            ->save();
    }
}
