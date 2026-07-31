<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bis zur Aufteilung der Anwesenheitsrechte war can_manage_users in fast jedem
 * RoleMiddleware-Gate als Fallback verdrahtet ("... || $canManageUsers"). Mit dem
 * Wegfall dieser Fallbacks verlieren Bestandsrollen still ihre bisherigen Zugaenge.
 * Der Backfill schreibt genau die Rechte fest, die diese Rollen faktisch schon hatten,
 * damit sie danach in der Rollenmatrix sichtbar und einzeln entziehbar sind.
 *
 * Bewusst nicht enthalten: can_manage_tasks (hatte nie einen Admin-Fallback im Gate)
 * und can_manage_events (bereits in 20260726120100 nachgezogen).
 */
final class BackfillRemovedAdminFallbackPermissions extends AbstractMigration
{
    private const RESTORED_PERMISSIONS = [
        'can_manage_project_members',
        'can_read_finances',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_manage_song_library',
        'can_manage_newsletters',
        'can_manage_mail_queue',
        'can_manage_sheet_archive',
        'can_manage_budget',
    ];

    public function up(): void
    {
        $assignments = implode(' = 1, ', self::RESTORED_PERMISSIONS) . ' = 1';

        $this->execute(
            "UPDATE roles
             SET {$assignments}
             WHERE can_manage_users = 1"
        );
    }

    public function down(): void
    {
        // Die Rechte sind nach dem Backfill regulaer gepflegte Einzelrechte - ein
        // pauschaler Entzug wuerde auch manuell vergebene Rechte loeschen.
    }
}
