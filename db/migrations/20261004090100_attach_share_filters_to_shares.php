<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Richtung dreht sich: Der Filter zeigt auf seine Freigabe, nicht mehr die
 * Freigabe auf den Filter. Damit räumt der Fremdschlüssel den Filter mit ab,
 * wenn die Freigabe verschwindet - auch über den Cascade vom Ordner her.
 */
final class AttachShareFiltersToShares extends AbstractMigration
{
    private const TABLES = [
        'file_folder_shares' => 'file_folder_share_id',
        'file_shares' => 'file_share_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            $this->execute(
                "UPDATE audience_filters f JOIN {$table} s ON s.audience_filter_id = f.id SET f.{$column} = s.id"
            );
        }

        // Prüfung vor dem destruktiven Schritt: jede Freigabe hat genau einen Filter.
        foreach (self::TABLES as $table => $column) {
            $broken = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} s
                 WHERE (SELECT COUNT(*) FROM audience_filters f WHERE f.{$column} = s.id) <> 1"
            )['n'] ?? 0);
            if ($broken > 0) {
                throw new RuntimeException(sprintf(
                    '%d Freigabe(n) in %s ohne eindeutigen Filter - Abbruch vor dem Entfernen von audience_filter_id.',
                    $broken,
                    $table
                ));
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->table($table)->dropForeignKey('audience_filter_id')->update();
            $this->table($table)->removeColumn('audience_filter_id')->update();
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            $this->table($table)
                ->addColumn('audience_filter_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'id'])
                ->update();
            $this->execute(
                "UPDATE {$table} s JOIN audience_filters f ON f.{$column} = s.id SET s.audience_filter_id = f.id"
            );
            $missing = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} WHERE audience_filter_id IS NULL"
            )['n'] ?? 0);
            if ($missing > 0) {
                throw new RuntimeException(sprintf('%d Freigabe(n) in %s ohne Filter - Rückbau abgebrochen.', $missing, $table));
            }
            $this->table($table)
                ->changeColumn('audience_filter_id', 'integer', ['null' => false, 'signed' => false])
                ->addForeignKey('audience_filter_id', 'audience_filters', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_' . $table . '_audience_filter',
                ])
                ->update();
            $this->execute("UPDATE audience_filters SET {$column} = NULL");
        }
    }
}
