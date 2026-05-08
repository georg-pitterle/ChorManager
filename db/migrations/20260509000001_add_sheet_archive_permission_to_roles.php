<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSheetArchivePermissionToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_sheet_archive TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_mail_queue;");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_sheet_archive;");
    }
}
