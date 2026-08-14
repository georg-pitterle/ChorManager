<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddImportHashToFinances extends AbstractMigration
{
    public function up(): void
    {
        // Fingerabdruck einer importierten Kontoauszugszeile. Manuell erfasste
        // Buchungen bleiben NULL - MySQL erlaubt beliebig viele NULL-Werte in
        // einem UNIQUE-Index, der Dublettenschutz greift also nur für Importe.
        $this->table('finances')
            ->addColumn('import_hash', 'char', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'payment_method',
            ])
            ->addIndex(['import_hash'], ['unique' => true, 'name' => 'uq_finances_import_hash'])
            ->update();
    }

    public function down(): void
    {
        $this->table('finances')
            ->removeIndexByName('uq_finances_import_hash')
            ->removeColumn('import_hash')
            ->update();
    }
}
