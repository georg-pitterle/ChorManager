<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillSheetArchivePermissionForDefaultRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_sheet_archive = 1
             WHERE LOWER(name) IN ('admin', 'chorleitung', 'chorleiter')"
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_sheet_archive = 0
             WHERE LOWER(name) IN ('admin', 'chorleitung', 'chorleiter')"
        );
    }
}
