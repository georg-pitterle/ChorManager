<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Gruppen einer Buchung stehen seit 20261122120000 in
 * `finance_group_assignments`, und alle Lese- und Schreibwege gehen dorthin.
 * Die alten Spalten `finances.group_name` und `finances.finance_group_id`
 * hielten nur noch eine zweite, veraltende Wahrheit fest.
 */
final class DropGroupColumnsFromFinances extends AbstractMigration
{
    /**
     * Buchungen, deren alte Gruppe keine Zuordnung hat - über die ID oder, wo sie
     * fehlt, über den Namen. Mitgezählt wird auch ein Name, zu dem es gar keine
     * Gruppe gibt: Die Übernahme konnte ihn nirgends hinschreiben. Sie alle
     * verlören mit dem DROP COLUMN ihre Gruppe.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird.
     */
    public const UNMIGRATED_GROUPS_SQL = <<<'SQL'
        SELECT COUNT(*) AS unmigrated
        FROM finances f
        LEFT JOIN finance_groups g
            ON f.finance_group_id IS NULL
            AND g.name = f.group_name COLLATE utf8mb4_general_ci
        WHERE (
                COALESCE(f.finance_group_id, g.id) IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM finance_group_assignments a
                    WHERE a.finance_id = f.id
                      AND a.finance_group_id = COALESCE(f.finance_group_id, g.id)
                )
            )
            OR (COALESCE(f.finance_group_id, g.id) IS NULL AND COALESCE(f.group_name, '') <> '')
        SQL;

    public function up(): void
    {
        // Prüfung vor dem Drop: danach ließe sich nicht mehr feststellen, ob die
        // Übernahme vollständig war.
        $unmigrated = (int) ($this->fetchRow(self::UNMIGRATED_GROUPS_SQL)['unmigrated'] ?? 0);
        if ($unmigrated > 0) {
            throw new RuntimeException(sprintf(
                '%d Buchungen haben ihre bisherige Gruppe nicht in finance_group_assignments. '
                    . 'Bitte 20261122120000 prüfen, bevor die alten Spalten entfernt werden.',
                $unmigrated
            ));
        }

        $this->execute('ALTER TABLE finances
            DROP FOREIGN KEY fk_finances_finance_group,
            DROP INDEX idx_finances_finance_group_id,
            DROP COLUMN finance_group_id,
            DROP COLUMN group_name');
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE finances
            ADD COLUMN group_name varchar(100) NULL DEFAULT NULL AFTER description,
            ADD COLUMN finance_group_id int(11) NULL DEFAULT NULL AFTER group_name,
            ADD KEY idx_finances_finance_group_id (finance_group_id),
            ADD CONSTRAINT fk_finances_finance_group
                FOREIGN KEY (finance_group_id) REFERENCES finance_groups (id)
                ON DELETE SET NULL");

        // Die alten Spalten nehmen eine Gruppe je Buchung auf: die mit der
        // kleinsten ID. Weitere Zuordnungen bleiben in der Tabelle stehen, die
        // der Rückbau von 20261122120000 dann selbst prüft.
        $this->execute('UPDATE finances f
            JOIN (
                SELECT finance_id, MIN(finance_group_id) AS group_id
                FROM finance_group_assignments
                GROUP BY finance_id
            ) a ON a.finance_id = f.id
            JOIN finance_groups g ON g.id = a.group_id
            SET f.finance_group_id = g.id, f.group_name = g.name');
    }
}
