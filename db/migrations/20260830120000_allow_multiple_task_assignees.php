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
    /**
     * Zählt die Zuweisungen, die der Rückbau nicht unterbringt: alles über die
     * erste je Aufgabe hinaus. `tasks.assigned_to` nimmt genau eine Person auf,
     * und `down()` setzt dort die kleinste Kennung je Aufgabe ein.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * CreateFinanceGroupsAndLink::ORPHANED_BUDGET_CATEGORIES_SQL.
     */
    public const SURPLUS_ASSIGNEES_SQL = <<<'SQL'
        SELECT COUNT(*) AS dropped
        FROM task_assignees a
        WHERE a.user_id <> (
            SELECT MIN(b.user_id) FROM task_assignees b WHERE b.task_id = a.task_id
        )
        SQL;

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
     *
     * Die Prüfung davor zählt deshalb nicht alle Zeilen, sondern genau die, die
     * der Rückbau nicht unterbringt. Ein Bestand, in dem jede Aufgabe höchstens
     * einen Zugewiesenen hat, verliert nichts und läuft weiter durch.
     */
    public function down(): void
    {
        $dropped = (int) ($this->fetchRow(self::SURPLUS_ASSIGNEES_SQL)['dropped'] ?? 0);

        if ($dropped > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Zuweisung(en) über die erste je Aufgabe hinaus '
                    . 'hätten in tasks.assigned_to keinen Platz und wären weg. Diese zuerst '
                    . 'bewusst selbst entfernen.',
                $dropped
            ));
        }

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
