<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Kategorie am Sponsor.
 *
 * Die bisherige Excel-Liste ordnete Sponsoren nach Branche, das Modul kannte
 * dafür nichts. Eine eigene Tabelle statt Freitext am Sponsor: So werden
 * "Gastronomie" und "gastronomie" nicht zu zwei Werten (die Kollation
 * unterscheidet Groß- und Kleinschreibung nicht), und eine spätere Pflegeseite
 * kann umbenennen, ohne jeden Sponsor anzufassen.
 *
 * Die Vorgaben sind bewusst allgemein - das Modul läuft in jedem Chor, die
 * Branchen eines einzelnen Chors gehören nicht in den Code. Weitere Kategorien
 * entstehen beim Tippen im Sponsor-Formular.
 */
final class AddSponsorCategories extends AbstractMigration
{
    public const DEFAULT_CATEGORIES = [
        'Einzelhandel',
        'Gastronomie',
        'Handwerk',
        'Dienstleistung',
        'Banken & Versicherungen',
        'Öffentliche Einrichtungen',
        'Kultur',
        'Medien',
        'Privatperson',
    ];

    /**
     * Was der Rückbau unwiederbringlich mitnähme: zugeordnete Sponsoren und
     * Kategorien, die nicht aus den Vorgaben stammen. Die Vorgaben selbst
     * zählen nicht, sonst ließe sich eine frische Installation nie zurückbauen.
     */
    public const USER_OWNED_CATEGORIES_SQL = <<<'SQL'
        SELECT
            (SELECT COUNT(*) FROM sponsors WHERE category_id IS NOT NULL)
            + (SELECT COUNT(*) FROM sponsor_categories WHERE name NOT IN (
                'Einzelhandel', 'Gastronomie', 'Handwerk', 'Dienstleistung', 'Banken & Versicherungen',
                'Öffentliche Einrichtungen', 'Kultur', 'Medien', 'Privatperson'
            )) AS rows_left
        SQL;

    public function up(): void
    {
        $this->table('sponsor_categories')
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uq_sponsor_categories_name'])
            ->create();

        $this->table('sponsor_categories')
            ->insert(array_map(static fn (string $name): array => ['name' => $name], self::DEFAULT_CATEGORIES))
            ->save();

        // Phinx legt Primärschlüssel als INT UNSIGNED an; die Fremdschlüsselspalte
        // muss denselben Typ haben, sonst scheitert der Constraint.
        $this->table('sponsors')
            ->addColumn('category_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => false,
                'after' => 'type',
            ])
            ->addForeignKey('category_id', 'sponsor_categories', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist mit dieser Migration
        // entstanden, die Zuordnungen und eigenen Kategorien aber im Betrieb.
        $rowsLeft = (int) ($this->fetchRow(self::USER_OWNED_CATEGORIES_SQL)['rows_left'] ?? 0);
        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Sponsor-Zuordnung(en) oder eigene Kategorie(n) wären mit '
                    . 'sponsor_categories unwiederbringlich weg. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('sponsors')
            ->dropForeignKey('category_id')
            ->removeColumn('category_id')
            ->update();

        $this->table('sponsor_categories')->drop()->update();
    }
}
