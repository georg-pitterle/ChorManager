<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllowNewslettersWithoutProject extends AbstractMigration
{
    public function up(): void
    {
        // Der Fremdschlüssel muss weichen, bevor die Spalte verändert werden kann.
        $this->table('newsletters')
            ->dropForeignKey('project_id')
            ->update();

        $this->table('newsletters')
            ->changeColumn('project_id', 'integer', ['null' => true])
            ->update();

        // SET NULL statt CASCADE: Ein gelöschtes Projekt darf die Versandhistorie
        // nicht mitnehmen, der Newsletter wird stattdessen projektlos.
        $this->table('newsletters')
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        // ACHTUNG DATENVERLUST: Diese Zeile löscht alle projektlosen Newsletter
        // unwiderruflich – über die Kaskaden reißt sie auch deren Empfänger,
        // Empfängerquellen und Archiveinträge mit, also genau die Versandhistorie,
        // die up() mit SET NULL bewusst geschützt hat. Sie ist technisch nötig,
        // um project_id anschließend wieder auf NOT NULL zu setzen.
        $this->execute('DELETE FROM newsletters WHERE project_id IS NULL');

        $this->table('newsletters')
            ->dropForeignKey('project_id')
            ->update();

        $this->table('newsletters')
            ->changeColumn('project_id', 'integer', ['null' => false])
            ->update();

        $this->table('newsletters')
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->update();
    }
}
