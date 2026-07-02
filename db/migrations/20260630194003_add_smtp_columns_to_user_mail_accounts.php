<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSmtpColumnsToUserMailAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_mail_accounts')
            ->addColumn('smtp_host', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'imap_encryption'])
            ->addColumn('smtp_port', 'integer', ['null' => true, 'default' => null, 'after' => 'smtp_host'])
            ->addColumn('smtp_encryption', 'string', ['limit' => 4, 'null' => true, 'default' => null, 'after' => 'smtp_port'])
            ->save();
    }

    public function down(): void
    {
        $this->table('user_mail_accounts')
            ->removeColumn('smtp_host')
            ->removeColumn('smtp_port')
            ->removeColumn('smtp_encryption')
            ->save();
    }
}
