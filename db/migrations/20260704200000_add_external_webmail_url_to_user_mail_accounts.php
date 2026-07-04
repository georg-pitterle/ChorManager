<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddExternalWebmailUrlToUserMailAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_mail_accounts')
            ->addColumn('external_webmail_url', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'mail_badge_enabled',
            ])
            ->save();
    }

    public function down(): void
    {
        $this->table('user_mail_accounts')
            ->removeColumn('external_webmail_url')
            ->save();
    }
}
