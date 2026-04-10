<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAttendanceManagementPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_attendance tinyint(1) NOT NULL DEFAULT 0;");
        $this->execute(
            "UPDATE roles SET can_manage_attendance = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung', 'Stimmvertretung', 'Ersatzvertretung');"
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE roles DROP COLUMN can_manage_attendance;');
    }
}
