<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recht "Stimmgruppen-Listen führen".
 *
 * Bewusst ohne Nachtrag für bestehende Rollen: "Eigene Stimmgruppe verwalten"
 * halten auch Admin, Vorstand und Kassier, und die Listen sollen nur die
 * Stimmvertretungen sehen. Wer es bekommt, entscheidet die Administration.
 */
final class AddCanKeepVoiceGroupListsToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE roles ADD COLUMN can_keep_voice_group_lists TINYINT(1) NOT NULL DEFAULT 0'
            . ' AFTER can_assign_own_voice_group_to_project;'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE roles DROP COLUMN can_keep_voice_group_lists;');
    }
}
