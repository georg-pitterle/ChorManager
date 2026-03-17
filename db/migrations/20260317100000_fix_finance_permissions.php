<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixFinancePermissions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE roles SET can_manage_finances = 1 WHERE name IN ('Admin', 'Vorstand')");
    }

    public function down(): void
    {
        $this->execute("UPDATE roles SET can_manage_finances = 0 WHERE name IN ('Admin', 'Vorstand')");
    }
}
