<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Anwesenheit/Anmeldung fuer alle Mitglieder hing bisher am can_manage_users-Fallback.
 * Bestandsrollen mit can_manage_users behalten ihre bisherige volle Sicht, indem sie
 * das neue Einzelrecht bekommen - ohne Backfill wuerde die Umstellung ihnen die
 * Sicht auf Mitglieder ausserhalb der eigenen Stimmgruppe entziehen.
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
        $this->execute("UPDATE roles SET can_manage_attendance_all = 0");
    }
}
