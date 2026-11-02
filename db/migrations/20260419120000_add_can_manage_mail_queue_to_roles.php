<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recht "Mail-Warteschlange verwalten".
 *
 * Reversibel über `change()`: Phinx nimmt das `addColumn()` beim Rückbau selbst
 * zurück, ein eigenes `down()` wäre nur eine Wiederholung.
 */
final class AddCanManageMailQueueToRoles extends AbstractMigration
{
    public function change(): void
    {
        $this->table('roles')
            ->addColumn('can_manage_mail_queue', 'boolean', ['default' => false, 'after' => 'can_manage_newsletters'])
            ->update();
    }
}
