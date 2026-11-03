<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Umfragen: eine Frage, feste Antworten, Stimmen der Zielgruppe.
 *
 * - polls: Frage, Auswahlart, Anonymität und Frist. Ein Statusfeld gibt es
 *   nicht; beendet ist eine Umfrage, deren Frist vorbei ist.
 * - poll_options: die Antworten in Eingabereihenfolge.
 * - poll_participants: wer abgestimmt hat. Primärschlüssel (poll_id, user_id),
 *   ohne Zeitstempel und ohne laufende Id.
 * - poll_votes: die Stimmen. Die Id ist eine zufällige UUID, nie eine laufende
 *   Nummer, und es gibt keine Zeitstempel. Bei anonymen Umfragen bleibt
 *   user_id leer.
 *
 * Warum ohne Reihenfolge: Trügen Teilnahme oder Stimme eine Reihenfolge, ließe
 * sich eine anonyme Stimme über jede fremde Quelle für die Reihenfolge der
 * Abstimmenden (etwa ein Proxy-Log) einer Person zuordnen.
 */
final class CreatePolls extends AbstractMigration
{
    public function up(): void
    {
        $this->table('polls')
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('description', 'text', ['null' => true, 'default' => null])
            ->addColumn('allows_multiple', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('is_anonymous', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('closes_at', 'datetime', ['null' => false])
            ->addColumn('created_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addTimestamps()
            ->addIndex(['closes_at'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('poll_options')
            ->addColumn('poll_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('label', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['poll_id', 'sort_order'])
            ->addForeignKey('poll_id', 'polls', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('poll_participants', ['id' => false, 'primary_key' => ['poll_id', 'user_id']])
            ->addColumn('poll_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addIndex(['user_id'])
            ->addForeignKey('poll_id', 'polls', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('poll_votes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('poll_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('option_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => true, 'default' => null])
            ->addIndex(['poll_id'])
            ->addIndex(['option_id', 'user_id'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addForeignKey('poll_id', 'polls', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('option_id', 'poll_options', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Fragen und Stimmen stehen nirgends sonst.
        $filled = [];
        foreach (['poll_votes', 'poll_participants', 'poll_options', 'polls'] as $table) {
            $rowsLeft = (int) ($this->fetchRow(
                sprintf('SELECT COUNT(*) AS rows_left FROM %s', $table)
            )['rows_left'] ?? 0);

            if ($rowsLeft > 0) {
                $filled[] = sprintf('%s: %d Zeile(n)', $table, $rowsLeft);
            }
        }

        if ($filled !== []) {
            throw new RuntimeException(
                'Rückbau abgebrochen, diese Daten wären unwiederbringlich weg: ' . implode(', ', $filled)
                    . '. Diese zuerst bewusst selbst entfernen.'
            );
        }

        $this->table('poll_votes')->drop()->update();
        $this->table('poll_participants')->drop()->update();
        $this->table('poll_options')->drop()->update();
        $this->table('polls')->drop()->update();
    }
}
