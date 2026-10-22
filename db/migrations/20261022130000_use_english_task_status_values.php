<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `tasks.status` und `tasks.priority` tragen künftig englische Bezeichner.
 *
 * Die beiden ENUMs hielten deutsche Werte ('Offen', 'In Bearbeitung',
 * 'Abgeschlossen', 'Blockiert' / 'Niedrig', 'Mittel', 'Hoch'). Nach
 * `instructions/naming.md` ist ein im Code adressierter Datenbankwert ein
 * englischer Bezeichner; das Deutsche gehört ins Label. Aufgefallen im
 * rotierenden Review-Lauf 51, entschieden in der Nacharbeit dazu.
 *
 * Das Deutsche steht jetzt in `Task::STATUS_LABELS` und
 * `Task::PRIORITY_LABELS`. Besonders unangenehm war der alte Wert
 * 'In Bearbeitung' mit seinem Leerzeichen: Er diente in
 * `projects/tasks.twig` zugleich als `data-drop-zone` der Kanban-Spalte und
 * ging so durch das JavaScript und über die Statusroute zurück.
 *
 * Umgestellt wird in drei Schritten je Spalte - Spalte weiten, Werte
 * umschreiben, Spalte auf das neue ENUM verengen. Ein ALTER direkt auf das neue
 * ENUM träfe die alten Werte, die darin nicht vorkommen, und machte sie zur
 * leeren Zeichenkette.
 */
final class UseEnglishTaskStatusValues extends AbstractMigration
{
    /**
     * Reihenfolge wie `Task::STATUSES` und damit wie die Spalten des
     * Kanban-Boards: offen, in Arbeit, blockiert, erledigt. Das alte ENUM stand
     * in einer anderen Reihenfolge als das Board, und die Vorlage brachte ihre
     * eigene mit.
     */
    private const NEW_STATUSES = "'open','in_progress','blocked','done'";
    private const OLD_STATUSES = "'Offen','In Bearbeitung','Abgeschlossen','Blockiert'";
    private const NEW_PRIORITIES = "'low','medium','high'";
    private const OLD_PRIORITIES = "'Niedrig','Mittel','Hoch'";

    /**
     * Zählt die Aufgaben, deren Zustand oder Dringlichkeit in keiner der beiden
     * Richtungen abgebildet ist.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * AddChannelToUserNotificationSettings::BELL_SETTINGS_SQL.
     *
     * Die Abfrage deckt beide Richtungen ab, weil beide Richtungen dieselbe
     * Gefahr tragen: Was die Zuordnung nicht kennt, bleibt beim Umschreiben
     * liegen und wird vom folgenden ENUM zur leeren Zeichenkette.
     *
     * Die Spalte heißt `unmapped_rows` und nicht `unmapped_tasks`: Der Test
     * tauscht den Tabellennamen per `strtr()` gegen eine Wegwerf-Tabelle, und
     * das trifft jedes Vorkommen der Zeichenkette - ein Alias mit `tasks` darin
     * wäre mit umbenannt worden, und die Prüfung hätte gegen eine Spalte
     * gelesen, die es nicht gibt. Sie hätte dann immer 0 gemeldet, also nie
     * angeschlagen.
     */
    public const UNMAPPED_TASKS_SQL = <<<'SQL'
        SELECT COUNT(*) AS unmapped_rows
        FROM tasks
        WHERE status NOT IN ('Offen', 'In Bearbeitung', 'Abgeschlossen', 'Blockiert',
                             'open', 'in_progress', 'done', 'blocked')
           OR priority NOT IN ('Niedrig', 'Mittel', 'Hoch', 'low', 'medium', 'high')
        SQL;

    public function up(): void
    {
        $this->abortOnUnmappedTasks();

        $this->execute("ALTER TABLE tasks MODIFY status VARCHAR(32) NOT NULL DEFAULT 'Offen'");
        $this->execute("ALTER TABLE tasks MODIFY priority VARCHAR(32) NOT NULL DEFAULT 'Mittel'");

        $this->execute(
            "UPDATE tasks SET status = CASE status
                WHEN 'Offen' THEN 'open'
                WHEN 'In Bearbeitung' THEN 'in_progress'
                WHEN 'Abgeschlossen' THEN 'done'
                WHEN 'Blockiert' THEN 'blocked'
                ELSE status
            END"
        );
        $this->execute(
            "UPDATE tasks SET priority = CASE priority
                WHEN 'Niedrig' THEN 'low'
                WHEN 'Mittel' THEN 'medium'
                WHEN 'Hoch' THEN 'high'
                ELSE priority
            END"
        );

        $this->execute(
            'ALTER TABLE tasks MODIFY status ENUM(' . self::NEW_STATUSES . ") NOT NULL DEFAULT 'open'"
        );
        $this->execute(
            'ALTER TABLE tasks MODIFY priority ENUM(' . self::NEW_PRIORITIES . ") NOT NULL DEFAULT 'medium'"
        );
    }

    public function down(): void
    {
        $this->abortOnUnmappedTasks();

        $this->execute("ALTER TABLE tasks MODIFY status VARCHAR(32) NOT NULL DEFAULT 'open'");
        $this->execute("ALTER TABLE tasks MODIFY priority VARCHAR(32) NOT NULL DEFAULT 'medium'");

        $this->execute(
            "UPDATE tasks SET status = CASE status
                WHEN 'open' THEN 'Offen'
                WHEN 'in_progress' THEN 'In Bearbeitung'
                WHEN 'done' THEN 'Abgeschlossen'
                WHEN 'blocked' THEN 'Blockiert'
                ELSE status
            END"
        );
        $this->execute(
            "UPDATE tasks SET priority = CASE priority
                WHEN 'low' THEN 'Niedrig'
                WHEN 'medium' THEN 'Mittel'
                WHEN 'high' THEN 'Hoch'
                ELSE priority
            END"
        );

        $this->execute(
            'ALTER TABLE tasks MODIFY status ENUM(' . self::OLD_STATUSES . ") NOT NULL DEFAULT 'Offen'"
        );
        $this->execute(
            'ALTER TABLE tasks MODIFY priority ENUM(' . self::OLD_PRIORITIES . ") NOT NULL DEFAULT 'Mittel'"
        );
    }

    /**
     * Prüfung vor dem verengenden Schritt, in beiden Richtungen dieselbe: Ein
     * Wert, den die Zuordnung nicht kennt, überlebt das `UPDATE` unverändert und
     * wird vom folgenden ENUM zur leeren Zeichenkette - der Zustand der Aufgabe
     * wäre dann still weg. Der Lauf bricht deshalb ab und nennt die Zahl.
     */
    private function abortOnUnmappedTasks(): void
    {
        $unmapped = (int) ($this->fetchRow(self::UNMAPPED_TASKS_SQL)['unmapped_rows'] ?? 0);

        if ($unmapped > 0) {
            throw new RuntimeException(sprintf(
                'Umstellung abgebrochen: %d Aufgabe(n) tragen einen Zustand oder eine Dringlichkeit, '
                    . 'die in keiner Richtung abgebildet ist. Das ENUM würde sie zur leeren '
                    . 'Zeichenkette machen. Diese Zeilen zuerst bewusst selbst richten.',
                $unmapped
            ));
        }
    }
}
