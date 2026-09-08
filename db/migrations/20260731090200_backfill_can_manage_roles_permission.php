<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zugriff auf /roles hing bisher an can_manage_users, zusätzlich bekam jede Rolle ab
 * Hierarchie-Level 80 can_manage_users implizit zugeschrieben. Beide Gruppen behalten
 * ihren bisherigen Zugang, indem sie das neue Einzelrecht explizit erhalten.
 */
final class BackfillCanManageRolesPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_roles = 1
             WHERE can_manage_users = 1 OR hierarchy_level >= 80"
        );
    }

    public function down(): void
    {
        // Kein pauschaler Entzug: can_manage_roles vergibt Rechte und ist damit
        // das heikelste Einzelrecht überhaupt - aber auch das, dessen stiller
        // Entzug am meisten anrichtet. Wer es inzwischen über die Rollenmatrix
        // bekommen hat, ist von den hier gesetzten Rollen nicht mehr zu
        // unterscheiden; ein UPDATE ohne WHERE könnte die letzte Rolle treffen,
        // die überhaupt noch Rechte vergeben darf. Gleiche Haltung wie in
        // 20260731090100 und 20260731090300.
    }
}
