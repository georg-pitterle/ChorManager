<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanManageOwnVoiceGroupToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles ADD COLUMN can_manage_own_voice_group TINYINT(1) NOT NULL DEFAULT 0"
            . " AFTER can_manage_backups;"
        );
        $this->execute(
            "UPDATE roles SET can_manage_own_voice_group = 1 WHERE hierarchy_level >= 40;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_own_voice_group;");
    }
}
