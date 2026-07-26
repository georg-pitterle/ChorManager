<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Termin-CRUD hing bisher an can_manage_users, die Terminarten an can_manage_master_data.
 * Beide Gruppen behalten ihre bisherigen Faehigkeiten, indem sie das neue Einzelrecht
 * bekommen - ohne Backfill wuerde die Umstellung Bestandsrollen Rechte entziehen.
 */
final class BackfillCanManageEventsPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_events = 1
             WHERE can_manage_users = 1 OR can_manage_master_data = 1"
        );
    }

    public function down(): void
    {
        $this->execute("UPDATE roles SET can_manage_events = 0");
    }
}
