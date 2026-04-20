<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSkippedStatusToMailQueue extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE mail_queue
            MODIFY COLUMN status enum('queued','sending','sent','skipped','failed','dead') NOT NULL DEFAULT 'queued'"
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE mail_queue
            SET status = 'sent'
            WHERE status = 'skipped'"
        );

        $this->execute(
            "ALTER TABLE mail_queue
            MODIFY COLUMN status enum('queued','sending','sent','failed','dead') NOT NULL DEFAULT 'queued'"
        );
    }
}
