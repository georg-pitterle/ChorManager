<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillBackupPermissionForAdminRole extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE roles SET can_manage_backups = 1 WHERE LOWER(name) = 'admin';");
    }

    public function down(): void
    {
        $this->execute("UPDATE roles SET can_manage_backups = 0 WHERE LOWER(name) = 'admin';");
    }
}
