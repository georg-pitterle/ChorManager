<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RequireFinanceAccountOnFinances extends AbstractMigration
{
    public function up(): void
    {
        // Eine Buchung ohne Zahlungskreis taucht in keiner Kontenübersicht auf,
        // zählt aber in den Jahressummen mit - der Kassabericht ginge damit nicht
        // auf. Alle Schreibwege (Erfassung, Import, Storno, Seed) setzen das Konto
        // bereits; die Spalte ließ die Lücke nur weiter offen.
        $orphaned = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS orphaned FROM finances WHERE finance_account_id IS NULL'
        )['orphaned'] ?? 0);

        if ($orphaned > 0) {
            throw new RuntimeException(sprintf(
                'Es gibt %d Buchung(en) ohne Konto. Diese zuerst einem Konto zuordnen, '
                    . 'sonst verlieren sie bei dieser Migration ihren Zahlungskreis.',
                $orphaned
            ));
        }

        $this->table('finances')
            ->changeColumn('finance_account_id', 'integer', [
                'null' => false,
                'signed' => false,
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('finances')
            ->changeColumn('finance_account_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => false,
            ])
            ->update();
    }
}
