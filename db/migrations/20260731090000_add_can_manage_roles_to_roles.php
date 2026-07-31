<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Rollenverwaltung wird ein eigenes Recht: can_manage_users darf Rollen nur noch
 * zuweisen, das Anlegen und Bearbeiten von Rollen (und damit das Vergeben von
 * Rechten) haengt ab jetzt an can_manage_roles.
 */
final class AddCanManageRolesToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles
             ADD COLUMN can_manage_roles TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_users;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_roles;");
    }
}
