<?php

declare(strict_types=1);

namespace Tests\Feature;

use AddIdToProjectSongAssignments;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

require_once dirname(__DIR__, 2) . '/db/migrations/20260421113000_add_id_to_project_song_assignments.php';

/**
 * 20260421113000 baut den Schlüssel um, auf dem der Fremdschlüssel auf
 * project_id sitzt. Wird der Constraint dabei nicht gelöst, weist MySQL beide
 * Richtungen ab - `up()` mit Fehler 150, `down()` mit Fehler 1553.
 *
 * Der Test spielt die Anweisungsfolge der Migration auf einer Wegwerf-Tabelle
 * mit derselben Struktur durch. Die echte Tabelle bleibt unangetastet, geprüft
 * wird aber genau die Reihenfolge, die die Migration ausführt.
 */
final class ProjectSongAssignmentKeySwapTest extends TestCase
{
    private const TABLE = 'test_project_song_assignments';
    private const CONSTRAINT = 'test_psa_project_fk';

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->dropScratchTable();
        $this->createLegacyScratchTable();
    }

    protected function tearDown(): void
    {
        $this->dropScratchTable();
        parent::tearDown();
    }

    public function testKeySwapRunsInBothDirectionsWithForeignKeyPresent(): void
    {
        foreach (AddIdToProjectSongAssignments::forwardStatements(self::TABLE, self::CONSTRAINT) as $statement) {
            Capsule::connection()->statement($statement);
        }

        $this->assertSame(['id'], $this->primaryKeyColumns(), 'Nach up() trägt id den Primärschlüssel.');
        $this->assertTrue($this->hasIndex('project_song_unique'), 'project_song_unique sichert das Paar ab.');
        $this->assertTrue($this->hasProjectForeignKey(), 'Der Fremdschlüssel ist wieder gesetzt.');

        foreach (AddIdToProjectSongAssignments::backwardStatements(self::TABLE, self::CONSTRAINT) as $statement) {
            Capsule::connection()->statement($statement);
        }

        $this->assertSame(
            ['project_id', 'song_id'],
            $this->primaryKeyColumns(),
            'Nach down() steht der zusammengesetzte Primärschlüssel wieder.'
        );
        $this->assertFalse($this->hasIndex('project_song_unique'), 'Der eindeutige Index ist zurückgenommen.');
        $this->assertFalse($this->hasColumn('id'), 'Die Spalte id ist entfernt.');
        $this->assertTrue($this->hasProjectForeignKey(), 'Der Fremdschlüssel überlebt auch den Rückbau.');
    }

    /**
     * Ohne Constraint-Namen fällt das Lösen weg - die Folge muss trotzdem
     * vollständig sein, sonst bliebe eine Datenbank ohne den Fremdschlüssel
     * auf halbem Weg stehen.
     */
    public function testStatementsWithoutForeignKeyContainOnlyKeySteps(): void
    {
        $statements = AddIdToProjectSongAssignments::forwardStatements(self::TABLE, null);

        $this->assertCount(3, $statements);
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('FOREIGN KEY', $statement);
        }
    }

    /**
     * Legt die Struktur an, die vor der Migration bestand: Primärschlüssel auf
     * dem Paar, Fremdschlüssel auf project_id ohne eigenen Index.
     */
    private function createLegacyScratchTable(): void
    {
        Capsule::connection()->statement(sprintf(
            'CREATE TABLE %s (
                project_id int(11) NOT NULL,
                song_id int(11) NOT NULL,
                note varchar(1000) DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (project_id, song_id),
                KEY %s_song_id_idx (song_id),
                CONSTRAINT %s FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            self::TABLE,
            self::TABLE,
            self::CONSTRAINT
        ));
    }

    private function dropScratchTable(): void
    {
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(): array
    {
        $rows = Capsule::connection()->select(sprintf(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = 'PRIMARY'
             ORDER BY SEQ_IN_INDEX",
            self::TABLE
        ));

        return array_map(static fn ($row): string => (string) $row->COLUMN_NAME, $rows);
    }

    private function hasIndex(string $indexName): bool
    {
        return $this->countRows(sprintf(
            "SELECT COUNT(*) AS total
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = '%s'",
            self::TABLE,
            $indexName
        )) > 0;
    }

    private function hasColumn(string $columnName): bool
    {
        return $this->countRows(sprintf(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s'",
            self::TABLE,
            $columnName
        )) > 0;
    }

    private function hasProjectForeignKey(): bool
    {
        return $this->countRows(sprintf(
            "SELECT COUNT(*) AS total
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'
               AND COLUMN_NAME = 'project_id' AND REFERENCED_TABLE_NAME = 'projects'",
            self::TABLE
        )) > 0;
    }

    private function countRows(string $sql): int
    {
        $rows = Capsule::connection()->select($sql);

        return isset($rows[0]) ? (int) $rows[0]->total : 0;
    }
}
