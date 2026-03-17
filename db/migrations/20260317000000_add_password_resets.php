<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPasswordResets extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('password_resets', ['id']);
        $table->addColumn('email', 'string', ['limit' => 255, ])
              ->addColumn('token', 'string', ['limit' => 255])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['email'], ['unique' => true])
              ->create();
    }
}
