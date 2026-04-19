<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddQueuedStatusToNewsletterRecipients extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE newsletter_recipients
            MODIFY COLUMN status enum('pending','queued','sent','failed') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE newsletter_recipients
            SET status = 'pending'
            WHERE status = 'queued'"
        );

        $this->execute(
            "ALTER TABLE newsletter_recipients
            MODIFY COLUMN status enum('pending','sent','failed') NOT NULL DEFAULT 'pending'"
        );
    }
}
