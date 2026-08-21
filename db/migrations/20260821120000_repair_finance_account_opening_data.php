<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Datenreparatur zu 20260815140000_create_finance_accounts.
 *
 * Jene Migration hat "Barkassa" und "Bankkonto" mit dem Stichtag des
 * Migrationstages angelegt, ihnen aber sämtliche Bestandsbuchungen zugeordnet.
 * Der Anfangsbestand deckt laut Konvention alles vor dem Stichtag ab, also
 * fielen alle älteren Buchungen aus der Bewegungsrechnung: Der Kassabericht
 * wies für jedes Vorjahr 0,00 € aus, während die Kennzahlen daneben die echten
 * Jahressummen zeigten.
 *
 * Der Stichtag wandert deshalb auf die früheste Buchung des Kontos zurück. Was
 * mit dem Anfangsbestand geschieht, hängt davon ab, ob er etwas behauptet:
 *
 * - 0,00 € hat die Kontomigration selbst eingetragen. Er behauptet nichts, das
 *   Konto beginnt bei seiner ersten Buchung leer - der Wert bleibt bei null.
 * - Ein eingetragener Betrag ist eine Aussage über den Bestand zum Stichtag. Er
 *   wird um die Summe der Buchungen gekürzt, die jetzt zusätzlich in die
 *   Bewegungsrechnung wandern. Dadurch bleibt jeder Bestand ab dem bisherigen
 *   Stichtag - und damit jede bereits geprüfte Zahl - unverändert, während die
 *   früheren Buchungen endlich in ihrem eigenen Geschäftsjahr auftauchen.
 *
 * Buchungen ohne Zahldatum sind offene Posten, gehören in keinen Bestand und
 * bleiben deshalb außen vor.
 */
final class RepairFinanceAccountOpeningData extends AbstractMigration
{
    /**
     * Steht als Konstante bereit, damit FinanceAccountOpeningRepairTest genau
     * die Anweisung prüfen kann, die hier auch ausgeführt wird.
     */
    public const REPAIR_SQL = <<<'SQL'
        UPDATE finance_accounts a
        JOIN (
            SELECT inner_account.id AS account_id,
                   MIN(f.payment_date) AS first_payment,
                   COALESCE(
                       SUM(CASE WHEN f.type = 'income' THEN f.amount ELSE -f.amount END),
                       0
                   ) AS net_before_opening
            FROM finance_accounts inner_account
            JOIN finances f
              ON f.finance_account_id = inner_account.id
             AND f.payment_date IS NOT NULL
             AND f.payment_date < inner_account.opening_date
            GROUP BY inner_account.id
        ) repair ON repair.account_id = a.id
        SET a.opening_date = repair.first_payment,
            a.opening_balance = CASE
                WHEN a.opening_balance = 0 THEN a.opening_balance
                ELSE a.opening_balance - repair.net_before_opening
            END
        SQL;

    public function up(): void
    {
        $this->execute(self::REPAIR_SQL);
    }

    public function down(): void
    {
        // Der bisherige Stichtag war ein Migrationsartefakt (der Tag der
        // Kontomigration) und ist nicht rekonstruierbar. Ihn zu erraten würde
        // dieselben Buchungen erneut aus dem Kassabericht werfen, deshalb bleibt
        // die Reparatur bestehen.
    }
}
