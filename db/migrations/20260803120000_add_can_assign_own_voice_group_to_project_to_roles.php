<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCanAssignOwnVoiceGroupToProjectToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles ADD COLUMN can_assign_own_voice_group_to_project TINYINT(1) NOT NULL DEFAULT 0"
            . " AFTER can_manage_own_voice_group;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_assign_own_voice_group_to_project;");
    }
}
