<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Filter bekommen ihren Besitzer als Spalte mit Fremdschlüssel. Gelöscht wird
 * dadurch über die Datenbank - auch bei Massenlöschung und bei Cascades von
 * weiter oben, wo keine Model-Events laufen. Ein weiteres Modul braucht nur
 * eine weitere Spalte, keine eigene Tabelle.
 */
final class AddOwnerColumnsToAudienceFilters extends AbstractMigration
{
    private const OWNERS = [
        'event_id' => 'events',
        'newsletter_id' => 'newsletters',
        'newsletter_template_id' => 'newsletter_templates',
        'file_folder_share_id' => 'file_folder_shares',
        'file_share_id' => 'file_shares',
    ];

    public function up(): void
    {
        foreach (self::OWNERS as $column => $owner) {
            $this->table('audience_filters')
                ->addColumn($column, 'integer', ['null' => true, 'signed' => $this->ownerIdSigned($owner)])
                ->addIndex([$column], ['name' => 'idx_audience_filters_' . $column])
                ->addForeignKey($column, $owner, 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                    'constraint' => 'fk_audience_filters_' . $column,
                ])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::OWNERS) as $column) {
            $this->table('audience_filters')->dropForeignKey($column)->update();
            $this->table('audience_filters')
                ->removeIndexByName('idx_audience_filters_' . $column)
                ->removeColumn($column)
                ->update();
        }
    }

    /**
     * Fremdschlüssel verlangen denselben Typ: ältere Tabellen haben signierte
     * Kennungen, die Dateiverwaltung unsignierte.
     */
    private function ownerIdSigned(string $owner): bool
    {
        $row = $this->fetchRow(sprintf("SHOW COLUMNS FROM `%s` LIKE 'id'", $owner));

        return !str_contains(strtolower((string) ($row['Type'] ?? '')), 'unsigned');
    }
}
