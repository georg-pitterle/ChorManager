<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRememberLogins extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('remember_logins');
        $table->addColumn('user_id', 'integer')
            ->addColumn('selector', 'string', ['limit' => 18])
            ->addColumn('token_hash', 'string', ['limit' => 255])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addIndex(['selector'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }
}
