<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanManageBudgetToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_budget TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_sheet_archive;");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_budget;");
    }
}