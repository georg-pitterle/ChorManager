<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Wer einen Eintrag angelegt hat, darf ihn mit can_create_own_sponsorships auch
 * wieder ändern. Dafür braucht es eine eigene Spalte: assigned_user_id ist
 * keine Entsprechung - "zuständig" wird vom Sponsoring-Team vergeben und sagt
 * nichts darüber aus, wer den Eintrag erfasst hat.
 *
 * Bestandsdaten bleiben bewusst ohne Urheber. Sie stammen aus der Zeit, in der
 * nur das Sponsoring-Team schreiben konnte, und bleiben damit dem Vollrecht
 * vorbehalten.
 */
final class AddCreatedByUserIdToSponsoring extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sponsors')
            ->addColumn('created_by_user_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'requests_blocked_note',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        $this->table('sponsorships')
            ->addColumn('created_by_user_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'assigned_user_id',
            ])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('sponsorships')
            ->dropForeignKey('created_by_user_id')
            ->removeColumn('created_by_user_id')
            ->update();

        $this->table('sponsors')
            ->dropForeignKey('created_by_user_id')
            ->removeColumn('created_by_user_id')
            ->update();
    }
}
