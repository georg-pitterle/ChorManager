<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixSponsoringPermissions extends AbstractMigration
{
    public function up(): void
    {
        // The original sponsoring migration added can_manage_sponsoring with DEFAULT 0
        // but did not set it to 1 for admin-level roles. This migration corrects that.
        $this->execute("UPDATE roles SET can_manage_sponsoring = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung');");
    }

    public function down(): void
    {
        $this->execute("UPDATE roles SET can_manage_sponsoring = 0 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung');");
    }
}
