<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanManageBackupsToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles ADD COLUMN can_manage_backups TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_budget;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_backups;");
    }
}
