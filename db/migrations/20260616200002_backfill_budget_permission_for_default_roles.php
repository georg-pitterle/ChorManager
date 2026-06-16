<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillBudgetPermissionForDefaultRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_budget = 1
             WHERE LOWER(name) IN ('admin', 'kassier', 'vorstand')"
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_budget = 0
             WHERE LOWER(name) IN ('admin', 'kassier', 'vorstand')"
        );
    }
}