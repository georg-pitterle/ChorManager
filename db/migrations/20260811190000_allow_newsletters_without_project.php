<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllowNewslettersWithoutProject extends AbstractMigration
{
    /**
     * Zählt die Newsletter, die in die wieder enge Spalte nicht mehr passen.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * RepairFinanceAccountOpeningData::REPAIR_SQL.
     */
    public const PROJECTLESS_NEWSLETTERS_SQL = <<<'SQL'
        SELECT COUNT(*) AS orphaned
        FROM newsletters
        WHERE project_id IS NULL
        SQL;

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
        // Prüfung vor dem destruktiven Schritt: project_id wieder auf NOT NULL zu
        // setzen ginge nur, wenn die projektlosen Newsletter vorher verschwinden -
        // und über die Kaskaden nähmen sie ihre Empfänger, Empfängerquellen und
        // Archiveinträge mit, also genau die Versandhistorie, die up() mit
        // SET NULL bewusst geschützt hat.
        //
        // Diese Zeilen still zu löschen wäre der falsche Preis für einen Rollback.
        // Der Lauf bricht deshalb ab und nennt ihre Zahl; wer wirklich zurück
        // will, ordnet sie vorher einem Projekt zu oder löscht sie bewusst selbst.
        $projectless = (int) ($this->fetchRow(self::PROJECTLESS_NEWSLETTERS_SQL)['orphaned'] ?? 0);

        if ($projectless > 0) {
            throw new RuntimeException(sprintf(
                'Rollback blocked: %d Newsletter haben kein Projekt. Sie würden mitsamt Empfängern, '
                    . 'Empfängerquellen und Archiveinträgen gelöscht. Diese zuerst einem Projekt '
                    . 'zuordnen oder bewusst selbst entfernen.',
                $projectless
            ));
        }

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
