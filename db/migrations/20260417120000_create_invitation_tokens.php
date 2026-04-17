<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInvitationTokens extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('invitation_tokens');
        $table
            ->addColumn('user_id', 'integer', ['signed' => true])
            ->addColumn('selector', 'string', ['limit' => 64])
            ->addColumn('token_hash', 'string', ['limit' => 255])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['selector'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
