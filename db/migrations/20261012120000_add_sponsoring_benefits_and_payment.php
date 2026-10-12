<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gegenleistungen und Zahlungsstand im Sponsoring.
 *
 * Was ein Sponsor für sein Geld bekommt - Logo im Programmheft, Freikarten,
 * Nennung bei der Ansage - stand bisher höchstens in den Notizen einer
 * Vereinbarung, und ob gezahlt wurde, nirgends. Beides ist aber die Frage vor
 * jedem Konzert und in jedem Gespräch mit dem Sponsor.
 *
 * - sponsor_package_benefits: die Vorgabe eines Pakets. Eine neue Vereinbarung
 *   übernimmt sie als Kopie.
 * - sponsorship_benefits: die Leistungen einer Vereinbarung, je abhakbar.
 *   Bewusst eine Kopie und kein Verweis auf das Paket: was ausgehandelt wurde,
 *   darf sich nicht ändern, wenn später jemand das Paket überarbeitet.
 * - sponsorships.paid_at: der Tag, an dem das Geld da war.
 *
 * Bestandsdaten bekommen keine Leistungen. Woraus sie bestanden, steht - wenn
 * überhaupt - als Freitext in den Notizen, und eine automatische Zerlegung
 * würde mehr raten als wissen.
 */
final class AddSponsoringBenefitsAndPayment extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sponsor_package_benefits')
            ->addColumn('package_id', 'integer', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['package_id', 'sort_order'])
            ->addForeignKey('package_id', 'sponsor_packages', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('sponsorship_benefits')
            ->addColumn('sponsorship_id', 'integer', ['null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('delivered_at', 'date', ['null' => true, 'default' => null])
            ->addColumn('delivered_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addIndex(['sponsorship_id', 'sort_order'])
            ->addForeignKey('sponsorship_id', 'sponsorships', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('delivered_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('sponsorships')
            ->addColumn('paid_at', 'date', ['null' => true, 'default' => null, 'after' => 'amount'])
            ->update();
    }

    public function down(): void
    {
        $this->table('sponsorships')
            ->removeColumn('paid_at')
            ->update();

        $this->table('sponsorship_benefits')->drop()->save();
        $this->table('sponsor_package_benefits')->drop()->save();
    }
}
