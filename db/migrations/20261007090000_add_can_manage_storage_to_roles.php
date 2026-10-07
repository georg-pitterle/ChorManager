<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recht für die Speicherplatz-Übersicht und später die Aufräum-Funktionen.
 *
 * Vergeben wird es an die Rollen mit dem höchsten vorhandenen Level, nicht an
 * eine Rolle namens "Admin": Rollen sind pro Installation frei benannt, und das
 * Level 100 der Ersteinrichtung lässt sich nachträglich ändern. So hat das Recht
 * nach der Migration immer mindestens eine Rolle.
 */
final class AddCanManageStorageToRoles extends AbstractMigration
{
    /**
     * Die abgeleitete Tabelle ist nötig: MySQL erlaubt in einem UPDATE keine
     * Unterabfrage auf dieselbe Tabelle (Fehler 1093).
     */
    public const GRANT_SQL = 'UPDATE roles SET can_manage_storage = 1 WHERE hierarchy_level = '
        . '(SELECT max_level FROM (SELECT MAX(hierarchy_level) AS max_level FROM roles) AS highest)';

    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles
             ADD COLUMN can_manage_storage TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_files;"
        );
        $this->execute(self::GRANT_SQL . ';');
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_storage;");
    }
}
