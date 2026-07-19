<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRegistrationReminderToMailTypeEnum extends AbstractMigration
{
    public function up(): void
    {
        $this->table('mail_queue')
            ->changeColumn('mail_type', 'enum', [
                'values' => ['newsletter', 'invitation', 'password_reset', 'registration_reminder'],
            ])
            ->update();
    }

    public function down(): void
    {
        // Fallback to 'invitation': registration reminders are event/registration
        // mail like invitations, not bulk newsletters, so remapping avoids
        // accidentally pulling these rows into newsletter-only unsubscribe logic.
        $this->execute(
            "UPDATE mail_queue
            SET mail_type = 'invitation'
            WHERE mail_type = 'registration_reminder'"
        );

        $this->table('mail_queue')
            ->changeColumn('mail_type', 'enum', ['values' => ['newsletter', 'invitation', 'password_reset']])
            ->update();
    }
}
