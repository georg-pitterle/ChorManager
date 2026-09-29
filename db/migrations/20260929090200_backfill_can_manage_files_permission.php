<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ohne Vorbelegung könnte nach dem Einschalten des Moduls niemand einen
 * Teamordner anlegen. Das Recht bekommen die Rollen, die schon Rollen
 * verwalten dürfen - sie könnten es sich ohnehin selbst geben.
 */
final class BackfillCanManageFilesPermission extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE roles SET can_manage_files = 1 WHERE can_manage_roles = 1");
    }

    public function down(): void
    {
        // Kein Entzug: Wer das Recht inzwischen bewusst über die Rollenmatrix
        // bekommen hat, ist von den hier gesetzten Rollen nicht zu unterscheiden.
        // Die Spalte selbst entfernt 20260929090100 beim Rückbau.
    }
}
