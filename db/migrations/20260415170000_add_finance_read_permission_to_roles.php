<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFinanceReadPermissionToRoles extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('roles');

        if (!$table->hasColumn('can_read_finances')) {
            $table
                ->addColumn('can_read_finances', 'boolean', [
                    'default' => 0,
                    'null' => false,
                    'after' => 'can_manage_project_members',
                ])
                ->update();
        }

        $this->execute('UPDATE roles SET can_read_finances = 1 WHERE can_manage_finances = 1');
    }

    public function down(): void
    {
        $table = $this->table('roles');

        if ($table->hasColumn('can_read_finances')) {
            $table->removeColumn('can_read_finances')->update();
        }
    }
}
