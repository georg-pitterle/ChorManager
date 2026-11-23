<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Eine Aufgabe überlebt die Löschung ihres Erstellers.
 *
 * `tasks.created_by` zeigte mit ON DELETE CASCADE auf `users` und war damit die
 * einzige Ersteller-Spalte im Schema, die ihre Zeile mitnimmt: `files`,
 * `file_folders`, `polls`, `songs`, `sponsors` und `voice_group_lists` setzen
 * den Ersteller auf NULL, `newsletters` und `newsletter_templates` blockieren
 * die Löschung mit RESTRICT.
 *
 * Heute fällt das nicht auf, weil Mitglieder nur archiviert werden - es gibt
 * keinen Aufrufer für `UserPersistence::delete()`. Die Methode steht aber
 * bereit und ist in ihrem Kommentar ausdrücklich für eine später ergänzte
 * DSGVO-Löschung vorgesehen. In dem Augenblick würde das Löschen eines
 * Mitglieds jede von ihm angelegte Aufgabe mitnehmen - auch die, die anderen
 * zugewiesen ist, mitsamt ihren Zuweisungen, Notizen und Anhängen, die alle
 * ihrerseits an der Aufgabe hängen. Gefunden im Review-Lauf 83, entschieden in
 * der Nacharbeit dazu.
 *
 * SET NULL statt RESTRICT: Eine DSGVO-Löschung muss durchlaufen können, ohne
 * dass vorher jemand von Hand alle Aufgaben eines Mitglieds umhängt. Die
 * Aufgabe selbst gehört dem Projekt, nicht ihrem Ersteller - angezeigt wird der
 * Ersteller in keiner Maske, die Beziehung `Task::createdBy()` hat heute keinen
 * Aufrufer. Nach der Löschung steht dort NULL und damit "unbekannt", was genau
 * der Lage entspricht.
 *
 * Verworfen wurde, die Spalte stattdessen auf ein Sammelkonto umzubiegen. Das
 * hieße, eine Zeile zu erfinden, die kein Mitglied ist, und jede Maske müsste
 * sie wieder herausfiltern; NULL sagt dasselbe, ohne neuen Zustand.
 */
final class KeepTasksWhenTheirCreatorIsDeleted extends AbstractMigration
{
    private const CONSTRAINT = 'tasks_creator_fk';

    /**
     * Was der Rückbau nicht unterbringt: eine Aufgabe ohne Ersteller. Genau die
     * entsteht erst durch diese Migration, und die Spalte wieder auf NOT NULL zu
     * stellen hieße, ihr einen beliebigen Ersteller zu geben oder sie zu
     * verlieren. Die Abfrage steht als Konstante, damit
     * `DestructiveMigrationGuardTest` genau die prüft, die auch läuft.
     */
    public const CREATORLESS_TASKS_SQL =
        'SELECT COUNT(*) AS rows_left FROM tasks WHERE created_by IS NULL';

    public function up(): void
    {
        // Erst der Fremdschlüssel, dann die Spalte: MySQL lässt die Nullbarkeit
        // einer gebundenen Spalte nicht ändern, solange die Regel darauf steht.
        $this->table('tasks')->dropForeignKey('created_by')->update();

        $this->table('tasks')
            ->changeColumn('created_by', 'integer', ['null' => true, 'signed' => true])
            ->update();

        // users.id stammt aus der SQL-Zeit und ist int(11) signed - deshalb hier
        // kein 'signed' => false, siehe /phinx-migration.
        $this->table('tasks')
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => self::CONSTRAINT,
            ])
            ->update();
    }

    public function down(): void
    {
        // Vollständigkeitsprüfung vor der Verengung: Eine Aufgabe ohne Ersteller
        // passt in die alte Spalte nicht mehr hinein.
        $creatorless = (int) ($this->fetchRow(self::CREATORLESS_TASKS_SQL)['rows_left'] ?? 0);

        if ($creatorless > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d %s ohne Ersteller. Das passt nicht in die wieder '
                . 'verpflichtende Spalte - erst von Hand einen Ersteller eintragen oder '
                . 'bewusst selbst entfernen.',
                $creatorless,
                $creatorless === 1 ? 'Aufgabe' : 'Aufgaben'
            ));
        }

        $this->table('tasks')->dropForeignKey('created_by')->update();

        $this->table('tasks')
            ->changeColumn('created_by', 'integer', ['null' => false, 'signed' => true])
            ->update();

        $this->table('tasks')
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => self::CONSTRAINT,
            ])
            ->update();
    }
}
