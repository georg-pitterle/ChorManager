<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUniqueReversalOfIdToFinances extends AbstractMigration
{
    public function up(): void
    {
        // Zu einer Buchung darf es höchstens eine Gegenbuchung geben. Ohne
        // Constraint können zwei gleichzeitige Storno-Requests beide durchkommen.
        // Der neue Index wird zuerst angelegt, damit der Fremdschlüssel auf
        // reversal_of_id durchgehend gedeckt bleibt.
        $this->table('finances')
            ->addIndex(['reversal_of_id'], [
                'name' => 'uq_finances_reversal_of_id',
                'unique' => true,
            ])
            ->update();

        $this->table('finances')
            ->removeIndexByName('idx_finances_reversal_of_id')
            ->update();
    }

    public function down(): void
    {
        $this->table('finances')
            ->addIndex(['reversal_of_id'], ['name' => 'idx_finances_reversal_of_id'])
            ->update();

        $this->table('finances')
            ->removeIndexByName('uq_finances_reversal_of_id')
            ->update();
    }
}
