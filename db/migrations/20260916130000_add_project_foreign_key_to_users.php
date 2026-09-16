<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zieht den fehlenden Fremdschlüssel auf users.last_project_id nach.
 *
 * Die Spalte merkt sich die zuletzt gewählte Projektauswahl der Auswertungen.
 * Sie zeigt auf projects.id, hatte aber als einzige solche Spalte im Schema
 * keinen Constraint: Nach dem Löschen eines Projekts blieb dessen Nummer in
 * jeder Zeile stehen, die sie zuletzt gewählt hatte.
 *
 * Folgen hatte das bisher keine, weil EvaluationController::resolveDefaultProjectId()
 * den Wert vor dem Verwenden gegen die zugänglichen Projekte prüft und eine
 * unbekannte Nummer verwirft. Die Zusicherung lag damit allein in der Anwendung -
 * genau die Lage, die 20260825120000 für calendar_subscription_tokens.user_id
 * schon einmal aufgelöst hat.
 *
 * SET NULL statt CASCADE: Das gelöschte Projekt nimmt nur die Erinnerung an die
 * Auswahl mit, nicht das Mitglied. Die Auswertungen fallen dann auf das laufende
 * Projekt zurück, also auf denselben Weg wie bei einem Mitglied, das noch nie
 * etwas gewählt hat.
 */
final class AddProjectForeignKeyToUsers extends AbstractMigration
{
    private const CONSTRAINT = 'fk_users_last_project';

    public function up(): void
    {
        // Karteileichen müssen weg, bevor der Constraint greifen kann - sonst
        // scheitert das ALTER an genau den Zeilen, die es aufräumen soll.
        // Gelöscht wird nichts: Die Nummer verweist auf ein Projekt, das es
        // nicht mehr gibt, und war schon vorher wertlos. Gleiches Vorgehen wie
        // in 20260825120000.
        $this->execute(
            'UPDATE users u
             LEFT JOIN projects p ON p.id = u.last_project_id
             SET u.last_project_id = NULL
             WHERE u.last_project_id IS NOT NULL
               AND p.id IS NULL'
        );

        $this->table('users')
            ->addForeignKey('last_project_id', 'projects', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => self::CONSTRAINT,
            ])
            ->update();
    }

    public function down(): void
    {
        // Die im up() geleerten Auswahlen kommen nicht zurück - sie verwiesen
        // auf gelöschte Projekte. Wer betroffen war, wählt beim nächsten Aufruf
        // der Auswertungen erneut.
        $this->table('users')
            ->dropForeignKey('last_project_id', self::CONSTRAINT)
            ->update();
    }
}
