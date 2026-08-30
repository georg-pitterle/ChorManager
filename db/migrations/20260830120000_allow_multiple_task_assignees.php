<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Eine Aufgabe konnte bisher genau einer Person gehören - `tasks.assigned_to`
 * ist eine einzelne Spalte mit Fremdschlüssel. In der Praxis erledigen Aufgaben
 * oft mehrere gemeinsam; wer das abbilden wollte, legte die Aufgabe zweimal an.
 *
 * Zugewiesen wird weiterhin nur an Personen, nicht an Rollen: Wer zuständig ist,
 * soll nicht still wechseln, sobald jemand eine Rolle abgibt.
 */
final class AllowMultipleTaskAssignees extends AbstractMigration
{
    public function up(): void
    {
        $this->table('task_assignees', ['id' => false, 'primary_key' => ['task_id', 'user_id']])
            ->addColumn('task_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('task_id', 'tasks', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->execute(
            'INSERT INTO task_assignees (task_id, user_id)
             SELECT id, assigned_to FROM tasks WHERE assigned_to IS NOT NULL'
        );

        // Prüfung vor dem Drop: danach ließe sich nicht mehr feststellen, ob die
        // Übernahme vollständig war, und die Zuweisungen wären weg.
        $expected = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS assigned FROM tasks WHERE assigned_to IS NOT NULL'
        )['assigned'] ?? 0);
        $migrated = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS migrated FROM task_assignees'
        )['migrated'] ?? 0);

        if ($expected !== $migrated) {
            throw new RuntimeException(sprintf(
                'Von %d zugewiesenen Aufgaben sind nur %d übernommen worden. '
                    . 'Die Spalte tasks.assigned_to bleibt deshalb erhalten.',
                $expected,
                $migrated
            ));
        }

        $this->table('tasks')
            ->dropForeignKey('assigned_to')
            ->update();

        $this->table('tasks')
            ->removeColumn('assigned_to')
            ->update();
    }

    /**
     * Schreibt je Aufgabe **eine** Zuweisung zurück - mehr fasst die Spalte
     * nicht. Bei einer Aufgabe mit mehreren Zugewiesenen bleibt die mit der
     * kleinsten Kennung übrig, die übrigen gehen verloren. Das lässt sich nicht
     * vermeiden, wenn das Ziel nur eine Person aufnehmen kann.
     */
    public function down(): void
    {
        $this->table('tasks')
            ->addColumn('assigned_to', 'integer', ['null' => true, 'default' => null, 'after' => 'description'])
            ->update();

        $this->execute(
            'UPDATE tasks t
             SET assigned_to = (
                 SELECT MIN(a.user_id) FROM task_assignees a WHERE a.task_id = t.id
             )'
        );

        $this->table('tasks')
            ->addForeignKey('assigned_to', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addIndex(['assigned_to'], ['name' => 'assigned_to_idx'])
            ->update();

        $this->table('task_assignees')->drop()->save();
    }
}
