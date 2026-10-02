<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ordner- und Dateifreigaben bekommen statt target_type/reference_id einen
 * Zielgruppen-Filter. Jede alte Freigabe wird ein Filter mit genau einer
 * Bedingung (all_members: keine Bedingung) - an den Rechten ändert sich nichts.
 *
 * Reihenfolge nach instructions/database.md: Spalte anlegen, befüllen, prüfen,
 * erst dann alte Spalten entfernen.
 */
final class MoveFileSharesToAudienceFilters extends AbstractMigration
{
    private const TABLES = ['file_folder_shares', 'file_shares'];

    private const OWNER_COLUMN = ['file_folder_shares' => 'folder_id', 'file_shares' => 'file_id'];

    private const UNIQUE_INDEX = [
        'file_folder_shares' => 'uniq_file_folder_shares_target',
        'file_shares' => 'uniq_file_shares_target',
    ];

    /** all_members fehlt bewusst: daraus wird ein Filter ohne Bedingung. */
    private const CATEGORY_FOR_TYPE = [
        'role' => 'role',
        'voice_group' => 'voice_group',
        'user' => 'user',
        'project_members' => 'project',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->table($table)
                ->addColumn('audience_filter_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'id'])
                ->update();
        }

        $now = date('Y-m-d H:i:s');
        $pdo = $this->getAdapter()->getConnection();
        foreach (self::TABLES as $table) {
            $rows = $this->fetchAll("SELECT id, target_type, reference_id FROM {$table} WHERE audience_filter_id IS NULL");
            foreach ($rows as $row) {
                $this->execute("INSERT INTO audience_filters (created_at) VALUES ('{$now}')");
                $filterId = (int) $pdo->lastInsertId();
                $category = self::CATEGORY_FOR_TYPE[$row['target_type']] ?? null;
                if ($category !== null) {
                    $this->execute(sprintf(
                        'INSERT INTO audience_filter_conditions (audience_filter_id, category, reference_id)'
                        . " VALUES (%d, '%s', %d)",
                        $filterId,
                        $category,
                        (int) $row['reference_id']
                    ));
                }
                $this->execute(sprintf(
                    'UPDATE %s SET audience_filter_id = %d WHERE id = %d',
                    $table,
                    $filterId,
                    (int) $row['id']
                ));
            }
        }

        // Prüfung vor jedem destruktiven Schritt.
        foreach (self::TABLES as $table) {
            $missing = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} WHERE audience_filter_id IS NULL"
            )['n'] ?? 0);
            if ($missing > 0) {
                throw new RuntimeException(sprintf(
                    '%d Freigabe(n) in %s ohne Filter - Abbruch vor dem Entfernen der alten Spalten.',
                    $missing,
                    $table
                ));
            }
        }

        // Der Unique-Index trug bisher auch den Fremdschlüssel auf die Besitzer-Spalte;
        // ohne eigenen Index (idx_<tabelle>_owner) lässt MySQL ihn nicht fallen.
        foreach (self::TABLES as $table) {
            $this->table($table)
                ->changeColumn('audience_filter_id', 'integer', ['null' => false, 'signed' => false])
                ->addForeignKey('audience_filter_id', 'audience_filters', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_' . $table . '_audience_filter',
                ])
                ->addIndex([self::OWNER_COLUMN[$table]], ['name' => 'idx_' . $table . '_owner'])
                ->removeIndexByName(self::UNIQUE_INDEX[$table])
                ->removeColumn('target_type')
                ->removeColumn('reference_id')
                ->update();
        }
    }

    public function down(): void
    {
        // Prüfung zuerst: Mehrere Bedingungen oder Untergruppen gibt es im
        // alten Modell nicht.
        foreach (self::TABLES as $table) {
            $complex = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} s WHERE
                    (SELECT COUNT(*) FROM audience_filter_conditions c
                        WHERE c.audience_filter_id = s.audience_filter_id) > 1
                 OR EXISTS (SELECT 1 FROM audience_filter_conditions c
                        WHERE c.audience_filter_id = s.audience_filter_id AND c.category = 'sub_voice')"
            )['n'] ?? 0);
            if ($complex > 0) {
                throw new RuntimeException(sprintf(
                    '%d Freigabe(n) in %s nutzen mehrere Bedingungen oder eine Untergruppe'
                    . ' - im alten Modell nicht darstellbar. Rückbau abgebrochen.',
                    $complex,
                    $table
                ));
            }
        }

        foreach (self::TABLES as $table) {
            $this->table($table)
                ->addColumn('target_type', 'enum', [
                    'values' => ['role', 'user', 'voice_group', 'project_members', 'all_members'],
                    'null' => true,
                    'after' => self::OWNER_COLUMN[$table],
                ])
                ->addColumn('reference_id', 'integer', ['null' => false, 'default' => 0, 'after' => 'target_type'])
                ->update();
            $this->execute(
                "UPDATE {$table} s
                 LEFT JOIN audience_filter_conditions c ON c.audience_filter_id = s.audience_filter_id
                 SET s.target_type = CASE c.category
                        WHEN 'project' THEN 'project_members'
                        WHEN 'role' THEN 'role'
                        WHEN 'voice_group' THEN 'voice_group'
                        WHEN 'user' THEN 'user'
                        ELSE 'all_members' END,
                     s.reference_id = COALESCE(c.reference_id, 0)"
            );
        }

        foreach (self::TABLES as $table) {
            $this->table($table)
                ->changeColumn('target_type', 'enum', [
                    'values' => ['role', 'user', 'voice_group', 'project_members', 'all_members'],
                    'null' => false,
                ])
                ->dropForeignKey('audience_filter_id')
                ->update();
            $this->table($table)
                ->removeColumn('audience_filter_id')
                ->addIndex([self::OWNER_COLUMN[$table], 'target_type', 'reference_id'], [
                    'unique' => true,
                    'name' => self::UNIQUE_INDEX[$table],
                ])
                ->addIndex(['target_type', 'reference_id'])
                ->update();
            $this->table($table)->removeIndexByName('idx_' . $table . '_owner')->update();
        }

        $this->execute('DELETE FROM audience_filters');
    }
}
