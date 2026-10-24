<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Leistungen des Sponsors.
 *
 * Was der Sponsor liefert - Banner oder Beachflag, den unterschriebenen
 * Vertrag, eine Sachleistung - stand in der Excel-Liste als einzelne
 * Ja/Nein-Spalten und im Modul nirgends. Aufgebaut wie die Gegenleistungen
 * (20261012120000_add_sponsoring_benefits_and_payment):
 *
 * - sponsor_package_deliverables: die Vorgabe eines Pakets. Eine neue
 *   Vereinbarung übernimmt sie als Kopie.
 * - sponsorship_deliverables: die Leistungen einer Vereinbarung, je abhakbar
 *   ("erhalten"). Kopie statt Verweis aus demselben Grund wie dort: was
 *   ausgehandelt wurde, ändert sich nicht mit dem Paket.
 *
 * Bestandsdaten bekommen keine Leistungen; woraus sie bestanden, ließe sich
 * nur raten.
 */
final class AddSponsoringDeliverables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sponsor_package_deliverables')
            ->addColumn('package_id', 'integer', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['package_id', 'sort_order'])
            ->addForeignKey('package_id', 'sponsor_packages', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('sponsorship_deliverables')
            ->addColumn('sponsorship_id', 'integer', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('received_at', 'date', ['null' => true, 'default' => null])
            ->addColumn('received_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addIndex(['sponsorship_id', 'sort_order'])
            ->addForeignKey('sponsorship_id', 'sponsorships', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('received_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabellen sind mit dieser Migration
        // entstanden, ihr Inhalt aber im Betrieb - ausgehandelte Leistungen und
        // ihr Erhalten-Stand stehen nirgends sonst.
        $filled = [];
        foreach (['sponsorship_deliverables', 'sponsor_package_deliverables'] as $table) {
            $rowsLeft = (int) ($this->fetchRow(
                sprintf('SELECT COUNT(*) AS rows_left FROM %s', $table)
            )['rows_left'] ?? 0);

            if ($rowsLeft > 0) {
                $filled[] = sprintf('%s: %d Zeile(n)', $table, $rowsLeft);
            }
        }

        if ($filled !== []) {
            throw new RuntimeException(
                'Rückbau abgebrochen, diese Daten wären unwiederbringlich weg: ' . implode(', ', $filled)
                    . '. Diese zuerst bewusst selbst entfernen.'
            );
        }

        $this->table('sponsorship_deliverables')->drop()->update();
        $this->table('sponsor_package_deliverables')->drop()->update();
    }
}
