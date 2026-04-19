<?php
use Phinx\Migration\AbstractMigration;

class AddCanManageMailQueueToRoles extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('roles');
        $table
            ->addColumn('can_manage_mail_queue', 'boolean', ['default' => false, 'after' => 'can_manage_newsletters'])
            ->update();
    }
}
