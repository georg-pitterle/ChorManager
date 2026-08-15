<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateFinanceAccounts extends AbstractMigration
{
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
        $this->table('finances')
            ->dropForeignKey('finance_account_id')
            ->removeIndexByName('idx_finances_finance_account_id')
            ->removeColumn('finance_account_id')
            ->update();

        $this->table('finance_accounts')->drop()->update();
    }
}
