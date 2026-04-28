<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIsPrivateToComments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE comments ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER comment');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE comments DROP COLUMN is_private');
    }
}
