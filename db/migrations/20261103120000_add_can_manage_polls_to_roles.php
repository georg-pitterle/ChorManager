<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recht "Umfragen verwalten". Bestehende Rollen bekommen es nicht nachgetragen:
 * Wer Umfragen anlegt, entscheidet jede Installation selbst. Level 100 darf
 * neue Rechte trotz Rechte-Deckel vergeben.
 */
final class AddCanManagePollsToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE roles ADD COLUMN can_manage_polls TINYINT(1) NOT NULL DEFAULT 0'
                . ' AFTER can_keep_voice_group_lists'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE roles DROP COLUMN can_manage_polls;');
    }
}
