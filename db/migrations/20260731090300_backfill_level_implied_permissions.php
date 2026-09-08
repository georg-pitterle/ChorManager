<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ein Hierarchie-Level ab 80 hat bisher vier Rechte implizit mitgeliefert
 * (SessionAuthService). Das Level entscheidet ab jetzt nur noch darüber, wessen
 * Zuordnungen man ändern darf; die vier Rechte werden deshalb einmalig explizit
 * festgeschrieben, damit Bestandsrollen nichts verlieren.
 *
 * Bewusst nicht weitergereicht werden die Rechte, die diese Rollen früher nur
 * indirekt über den can_manage_users-Fallback erreicht haben (Finanzen, Stammdaten,
 * Module): sie waren nie sichtbar vergeben und sind über die Rollenmatrix jederzeit
 * nachziehbar.
 */
final class BackfillLevelImpliedPermissions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE roles
             SET can_manage_users = 1,
                 can_edit_users = 1,
                 can_manage_project_members = 1,
                 can_manage_tasks = 1
             WHERE hierarchy_level >= 80"
        );
    }

    public function down(): void
    {
        // Ohne den impliziten Level-Bonus ließe sich nicht mehr unterscheiden, welche
        // Rechte vorher schon explizit gesetzt waren - deshalb kein pauschaler Entzug.
    }
}
