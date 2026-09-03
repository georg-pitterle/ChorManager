<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tauscht den zusammengesetzten Primärschlüssel (project_id, song_id) gegen eine
 * eigene id und sichert das Paar stattdessen über project_song_unique ab.
 *
 * Beide Richtungen bauen den Schlüssel um, auf dem der Fremdschlüssel auf
 * project_id sitzt: vorher trägt ihn der Primärschlüssel, nachher der eindeutige
 * Index - jeweils als führende Spalte. MySQL lässt den tragenden Schlüssel nicht
 * fallen, solange der Fremdschlüssel daran hängt (Fehler 1553 bzw. 150). Der
 * Constraint wird deshalb vorher gelöst und danach wieder gesetzt.
 *
 * Ohne dieses Lösen war die Migration in beide Richtungen unbrauchbar: `up()`
 * scheiterte auf genau den Altbeständen, für die es sie gibt, `down()` auf jeder
 * Datenbank.
 */
final class AddIdToProjectSongAssignments extends AbstractMigration
{
    public const TABLE = 'project_song_assignments';

    /**
     * Stehen als Fabrikmethoden bereit, damit AddIdToProjectSongAssignmentsTest
     * genau die Reihenfolge prüfen kann, die hier auch ausgeführt wird - gleiches
     * Muster wie RepairFinanceAccountOpeningData::REPAIR_SQL.
     *
     * @return list<string>
     */
    public static function forwardStatements(string $table, ?string $constraintName): array
    {
        return array_merge(
            self::dropForeignKeyStatements($table, $constraintName),
            [
                sprintf('ALTER TABLE %s DROP PRIMARY KEY', $table),
                sprintf('ALTER TABLE %s ADD COLUMN id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST', $table),
                sprintf('ALTER TABLE %s ADD UNIQUE KEY project_song_unique (project_id, song_id)', $table),
            ],
            // project_song_unique führt project_id an erster Stelle und deckt den
            // Fremdschlüssel damit wieder ab.
            self::addForeignKeyStatements($table, $constraintName)
        );
    }

    /**
     * @return list<string>
     */
    public static function backwardStatements(string $table, ?string $constraintName): array
    {
        return array_merge(
            self::dropForeignKeyStatements($table, $constraintName),
            [
                sprintf('ALTER TABLE %s DROP INDEX project_song_unique', $table),
                // DROP COLUMN nimmt den Primärschlüssel mit; ein eigenes
                // DROP PRIMARY KEY davor scheitert daran, dass id AUTO_INCREMENT
                // ist und ohne Schlüssel nicht bestehen darf.
                sprintf('ALTER TABLE %s DROP COLUMN id', $table),
                sprintf('ALTER TABLE %s ADD PRIMARY KEY (project_id, song_id)', $table),
            ],
            self::addForeignKeyStatements($table, $constraintName)
        );
    }

    public function up(): void
    {
        $hasIdColumn = $this->fetchRow("SHOW COLUMNS FROM project_song_assignments LIKE 'id'");
        if ($hasIdColumn) {
            return;
        }

        foreach (self::forwardStatements(self::TABLE, $this->projectForeignKeyName()) as $statement) {
            $this->execute($statement);
        }
    }

    public function down(): void
    {
        $hasIdColumn = $this->fetchRow("SHOW COLUMNS FROM project_song_assignments LIKE 'id'");
        if (!$hasIdColumn) {
            return;
        }

        foreach (self::backwardStatements(self::TABLE, $this->projectForeignKeyName()) as $statement) {
            $this->execute($statement);
        }
    }

    /**
     * Der Name wird nachgeschlagen statt angenommen: Ältere Datenbanken können
     * ihn von MySQL vergeben bekommen haben.
     */
    private function projectForeignKeyName(): ?string
    {
        $foreignKey = $this->fetchRow(sprintf(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '%s'
               AND COLUMN_NAME = 'project_id'
               AND REFERENCED_TABLE_NAME = 'projects'
             LIMIT 1",
            self::TABLE
        ));

        $constraintName = $foreignKey['CONSTRAINT_NAME'] ?? null;

        return $constraintName ? (string) $constraintName : null;
    }

    /**
     * @return list<string>
     */
    private static function dropForeignKeyStatements(string $table, ?string $constraintName): array
    {
        if ($constraintName === null) {
            return [];
        }

        return [sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraintName)];
    }

    /**
     * ON DELETE CASCADE entspricht der Definition aus
     * 20260421100000_add_repertoire_tables.
     *
     * @return list<string>
     */
    private static function addForeignKeyStatements(string $table, ?string $constraintName): array
    {
        if ($constraintName === null) {
            return [];
        }

        return [sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s'
            . ' FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE',
            $table,
            $constraintName
        )];
    }
}
