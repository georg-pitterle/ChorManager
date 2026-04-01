<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RemoveUnusedNewsletterStatuses extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE newsletters SET status = 'sent' WHERE status NOT IN ('draft', 'sent')");
        $this->execute(
            "ALTER TABLE newsletters MODIFY COLUMN status ENUM('draft', 'sent') NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        $this->execute(
            "ALTER TABLE newsletters MODIFY COLUMN status ENUM('draft', 'scheduled', 'sent', 'archived') NOT NULL DEFAULT 'draft'"
        );
    }
}