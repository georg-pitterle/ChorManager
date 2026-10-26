<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Stimmgruppen-Listen: Arbeitslisten der Stimmvertretungen.
 *
 * - voice_group_lists: eine Liste gehört der Stimmgruppe, nicht der Person,
 *   die sie angelegt hat - die Nachfolge in der Stimmvertretung findet sie vor.
 *   Das Projekt ist optional und fällt beim Löschen auf NULL zurück, statt die
 *   Notizen mitzunehmen.
 * - voice_group_list_columns: die selbst formulierten Fragen, als Häkchen oder
 *   Notiz.
 * - voice_group_list_entries: ein Eintrag je Mitglied und Spalte, nur solange
 *   etwas gesetzt ist. Welche Mitglieder Zeilen sind, wird nicht gespeichert,
 *   sondern bei jedem Aufruf aus Stimmgruppe und Projekt berechnet.
 */
final class CreateVoiceGroupLists extends AbstractMigration
{
    public function up(): void
    {
        $this->table('voice_group_lists')
            ->addColumn('voice_group_id', 'integer', ['null' => false])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('archived_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addTimestamps()
            ->addIndex(['voice_group_id'])
            ->addForeignKey('voice_group_id', 'voice_groups', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('voice_group_list_columns')
            ->addColumn('list_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('label', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('type', 'enum', ['values' => ['checkbox', 'note'], 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addTimestamps()
            ->addIndex(['list_id', 'sort_order'])
            ->addForeignKey('list_id', 'voice_group_lists', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('voice_group_list_entries')
            ->addColumn('column_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('checked_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('note', 'text', ['null' => true, 'default' => null])
            ->addColumn('updated_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addTimestamps()
            ->addIndex(['column_id', 'user_id'], ['unique' => true])
            ->addForeignKey('column_id', 'voice_group_list_columns', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('updated_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Listen, Fragen und Notizen der
        // Stimmvertretungen stehen nirgends sonst.
        $filled = [];
        foreach (['voice_group_list_entries', 'voice_group_list_columns', 'voice_group_lists'] as $table) {
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

        $this->table('voice_group_list_entries')->drop()->update();
        $this->table('voice_group_list_columns')->drop()->update();
        $this->table('voice_group_lists')->drop()->update();
    }
}
