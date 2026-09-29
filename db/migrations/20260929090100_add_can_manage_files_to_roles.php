<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recht für die Dateiverwaltung: Teamordner anlegen, Kontingente setzen und
 * sämtliche Freigaben ändern. Wer nur einzelne Ordner betreut, bekommt dafür
 * kein Rollenrecht, sondern die Stufe "Verwalten" auf diesen Ordner.
 */
final class AddCanManageFilesToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles
             ADD COLUMN can_manage_files TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_backups;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_files;");
    }
}
