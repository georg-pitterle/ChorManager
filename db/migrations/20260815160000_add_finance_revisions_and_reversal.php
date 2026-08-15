<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFinanceRevisionsAndReversal extends AbstractMigration
{
    public function up(): void
    {
        // Storno statt Löschen: Das Original bleibt stehen, die Gegenbuchung
        // verweist über reversal_of_id darauf. § 131 BAO verlangt, dass eine
        // Korrektur den ursprünglichen Inhalt nicht unkenntlich macht.
        $this->table('finances')
            ->addColumn('reversal_of_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'import_hash',
            ])
            ->addIndex(['reversal_of_id'], ['name' => 'idx_finances_reversal_of_id'])
            ->update();

        $this->table('finances')
            ->addForeignKey('reversal_of_id', 'finances', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->update();

        $this->table('finance_revisions')
            ->addColumn('finance_id', 'integer')
            ->addColumn('user_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('action', 'enum', ['values' => ['create', 'update', 'reverse']])
            // Nicht "changes" nennen: Eloquent belegt diesen Property-Namen intern
            // fuer sein Dirty-Tracking, ein gleichnamiges Attribut waere im Model
            // nicht mehr erreichbar.
            ->addColumn('change_set', 'text', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['finance_id'], ['name' => 'idx_finance_revisions_finance_id'])
            ->addIndex(['created_at'], ['name' => 'idx_finance_revisions_created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('finance_revisions')->drop()->update();

        $this->table('finances')
            ->dropForeignKey('reversal_of_id')
            ->removeIndexByName('idx_finances_reversal_of_id')
            ->removeColumn('reversal_of_id')
            ->update();
    }
}
