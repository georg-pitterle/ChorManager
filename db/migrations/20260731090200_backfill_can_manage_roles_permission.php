<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zugriff auf /roles hing bisher an can_manage_users, zusaetzlich bekam jede Rolle ab
 * Hierarchie-Level 80 can_manage_users implizit zugeschrieben. Beide Gruppen behalten
 * ihren bisherigen Zugang, indem sie das neue Einzelrecht explizit erhalten.
 */
final class BackfillCanManageRolesPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_roles = 1
             WHERE can_manage_users = 1 OR hierarchy_level >= 80"
        );
    }

    public function down(): void
    {
        $this->execute("UPDATE roles SET can_manage_roles = 0");
    }
}
