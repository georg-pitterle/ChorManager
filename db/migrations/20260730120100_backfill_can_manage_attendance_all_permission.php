<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Anwesenheit/Anmeldung für alle Mitglieder hing bisher am can_manage_users-Fallback.
 * Bestandsrollen mit can_manage_users behalten ihre bisherige volle Sicht, indem sie
 * das neue Einzelrecht bekommen - ohne Backfill würde die Umstellung ihnen die
 * Sicht auf Mitglieder außerhalb der eigenen Stimmgruppe entziehen.
 */
final class BackfillCanManageAttendanceAllPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_attendance_all = 1
             WHERE can_manage_users = 1"
        );
    }

    public function down(): void
    {
        // Kein pauschaler Entzug: Seit dem Backfill ist can_manage_attendance_all
        // ein regulär gepflegtes Einzelrecht. Wer es inzwischen über die
        // Rollenmatrix bekommen hat, ist von den hier gesetzten Rollen nicht mehr
        // zu unterscheiden - ein UPDATE ohne WHERE nähme beiden die volle Sicht
        // auf die Anwesenheit. Gleiche Haltung wie in 20260731090100 und
        // 20260731090300.
    }
}
