<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Termin-CRUD hing bisher an can_manage_users, die Terminarten an can_manage_master_data.
 * Beide Gruppen behalten ihre bisherigen Fähigkeiten, indem sie das neue Einzelrecht
 * bekommen - ohne Backfill würde die Umstellung Bestandsrollen Rechte entziehen.
 */
final class BackfillCanManageEventsPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_events = 1
             WHERE can_manage_users = 1 OR can_manage_master_data = 1"
        );
    }

    public function down(): void
    {
        // Kein pauschaler Entzug: Seit dem Backfill ist can_manage_events ein
        // regulär gepflegtes Einzelrecht. Wer es inzwischen über die Rollenmatrix
        // bekommen hat, ist von den hier gesetzten Rollen nicht mehr zu
        // unterscheiden - ein UPDATE ohne WHERE nähme beiden das Recht. Gleiche
        // Haltung wie in 20260731090100 und 20260731090300.
    }
}
