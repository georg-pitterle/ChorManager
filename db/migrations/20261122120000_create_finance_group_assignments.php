<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Eine Buchung der Kasse konnte bisher genau einer Gruppe angehören. Gruppen
 * beantworten aber zwei Fragen: wofür (Kostenart) und wozu (Anlass). Die neue
 * Zuordnungstabelle erlaubt mehrere Gruppen je Buchung; die alten Spalten
 * `finances.group_name` und `finances.finance_group_id` bleiben bis zur
 * Folgemigration stehen.
 */
final class CreateFinanceGroupAssignments extends AbstractMigration
{
    /**
     * Übernimmt die bisherige Einzelgruppe jeder Buchung - über die Gruppen-ID
     * und, wo sie fehlt, über den Namen. `INSERT IGNORE`, damit ein zweiter
     * Lauf keine Dubletten erzeugt.
     *
     * Steht als Konstante bereit, damit der Test genau die Anweisung prüft, die
     * hier auch ausgeführt wird.
     */
    public const BACKFILL_SQL = <<<'SQL'
        INSERT IGNORE INTO finance_group_assignments (finance_id, finance_group_id)
        SELECT f.id, COALESCE(f.finance_group_id, g.id)
        FROM finances f
        LEFT JOIN finance_groups g
            ON f.finance_group_id IS NULL
            AND g.name = f.group_name COLLATE utf8mb4_general_ci
        WHERE COALESCE(f.finance_group_id, g.id) IS NOT NULL
        SQL;

    /**
     * Zählt die Zuordnungen, die der Rückbau nicht unterbringt: Die alte Spalte
     * nimmt eine Gruppe je Buchung auf, und zwar die in `finance_group_id`.
     * Alles andere ginge mit dem DROP TABLE verloren.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird.
     */
    public const SURPLUS_ASSIGNMENTS_SQL = <<<'SQL'
        SELECT COUNT(*) AS dropped
        FROM finance_group_assignments a
        JOIN finances f ON f.id = a.finance_id
        WHERE f.finance_group_id IS NULL OR f.finance_group_id <> a.finance_group_id
        SQL;

    public function up(): void
    {
        $this->table('finance_group_assignments', ['id' => false, 'primary_key' => ['finance_id', 'finance_group_id']])
            ->addColumn('finance_id', 'integer', ['null' => false])
            ->addColumn('finance_group_id', 'integer', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['finance_group_id'])
            ->addForeignKey('finance_id', 'finances', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('finance_group_id', 'finance_groups', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->create();

        $this->execute(self::BACKFILL_SQL);
    }

    public function down(): void
    {
        $dropped = (int) ($this->fetchRow(self::SURPLUS_ASSIGNMENTS_SQL)['dropped'] ?? 0);
        if ($dropped > 0) {
            throw new RuntimeException(sprintf(
                '%d Gruppenzuordnungen passen nicht in finances.finance_group_id und gingen beim Rückbau verloren. '
                    . 'Bitte zuerst bereinigen.',
                $dropped
            ));
        }

        $this->table('finance_group_assignments')->drop()->save();
    }
}
