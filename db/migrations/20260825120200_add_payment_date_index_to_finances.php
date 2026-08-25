<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Kassabericht, Jahres-Kennzahlen und Kontostände filtern und gruppieren über
 * finances.payment_date. Indiziert waren bisher nur running_number,
 * finance_group_id, finance_account_id, import_hash und reversal_of_id - jede
 * Jahresauswertung las damit die ganze Tabelle.
 *
 * Bei Chorgrößen fällt das heute nicht auf; der Index kostet aber praktisch
 * nichts und hält die Auswertungen unabhängig von der Zeilenzahl.
 */
final class AddPaymentDateIndexToFinances extends AbstractMigration
{
    private const INDEX = 'idx_finances_payment_date';

    public function up(): void
    {
        $this->table('finances')
            ->addIndex(['payment_date'], ['name' => self::INDEX])
            ->update();
    }

    public function down(): void
    {
        $this->table('finances')
            ->removeIndexByName(self::INDEX)
            ->update();
    }
}
