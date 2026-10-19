<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateFinanceAccounts extends AbstractMigration
{
    /**
     * Zählt die Konten, die nicht aus `up()` stammen: alles, was nicht eines der
     * beiden unberührten Vorgabekonten ist. Ein bearbeiteter Name, eine
     * eingetragene IBAN oder ein geänderter Anfangsstand machen aus der Vorgabe
     * eine Eingabe, die der Rückbau nicht wegwerfen darf.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird.
     */
    public const USER_OWNED_ACCOUNTS_SQL = <<<'SQL'
        SELECT COUNT(*) AS rows_left
        FROM finance_accounts
        WHERE name NOT IN ('Barkassa', 'Bankkonto')
           OR iban IS NOT NULL
           OR opening_balance <> '0.00'
        SQL;

    public function up(): void
    {
        $this->table('finance_accounts')
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('type', 'enum', ['values' => ['cash', 'bank']])
            ->addColumn('iban', 'string', ['limit' => 34, 'null' => true, 'default' => null])
            ->addColumn('opening_balance', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('opening_date', 'date')
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => null])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uq_finance_account_name'])
            ->create();

        // Phinx legt Primärschlüssel als INT UNSIGNED an; die Fremdschlüsselspalte
        // muss denselben Typ haben, sonst scheitert der Constraint.
        $this->table('finances')
            ->addColumn('finance_account_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => false,
                'after' => 'payment_method',
            ])
            ->addIndex(['finance_account_id'], ['name' => 'idx_finances_finance_account_id'])
            ->update();

        // Die Zahlungsart bleibt als denormalisiertes Spiegelfeld bestehen (gleiches
        // Muster wie group_name neben finance_group_id), damit Auswertung, PDF und
        // Tabellen unverändert weiterlaufen.
        $openingDate = date('Y-m-d');
        $this->execute(sprintf(
            "INSERT INTO finance_accounts (name, type, iban, opening_balance, opening_date, is_active, sort_order)
             VALUES ('Barkassa', 'cash', NULL, '0.00', '%s', 1, 1),
                    ('Bankkonto', 'bank', NULL, '0.00', '%s', 1, 2)",
            $openingDate,
            $openingDate
        ));

        $this->execute(
            "UPDATE finances f
             JOIN finance_accounts a ON a.type = 'cash'
             SET f.finance_account_id = a.id
             WHERE f.payment_method = 'cash'"
        );
        $this->execute(
            "UPDATE finances f
             JOIN finance_accounts a ON a.type = 'bank'
             SET f.finance_account_id = a.id
             WHERE f.payment_method = 'bank_transfer'"
        );

        $this->table('finances')
            ->addForeignKey('finance_account_id', 'finance_accounts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - angelegte und umbenannte Konten
        // kamen im Betrieb dazu und stehen nirgends sonst.
        //
        // Die beiden Vorgabekonten aus `up()` zählen nicht mit, solange sie
        // unberührt sind: Sie zurückzunehmen vernichtet nichts, was es vorher
        // gab, und stünden sie mit in der Zählung, ließe sich eine frische
        // Installation nie mehr zurückbauen. Sobald aber Name, IBAN oder
        // Anfangsstand bearbeitet wurden, ist es eine Eingabe wie jede andere
        // und die Prüfung schlägt an.
        $rowsLeft = (int) ($this->fetchRow(self::USER_OWNED_ACCOUNTS_SQL)['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: finance_accounts enthält %d Konto/Konten, die nicht die '
                    . 'unberührten Vorgaben sind und mit der Tabelle unwiederbringlich weg wären. '
                    . 'Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('finances')
            ->dropForeignKey('finance_account_id')
            ->removeIndexByName('idx_finances_finance_account_id')
            ->removeColumn('finance_account_id')
            ->update();

        $this->table('finance_accounts')->drop()->update();
    }
}
