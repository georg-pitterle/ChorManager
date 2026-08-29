<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bisher hing das gesamte Sponsoring an can_manage_sponsoring. Wer eine eigene
 * Firma anfragen wollte, brauchte damit auch das Recht, fremde Vereinbarungen
 * zu ändern und Pakete zu pflegen - deshalb lief die Excel-Liste im Chor
 * parallel weiter. Das neue Recht erlaubt Lesen sowie eigene Vereinbarungen,
 * ohne die Verwaltung mitzuliefern.
 */
final class AddCanCreateOwnSponsorshipsToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE roles
             ADD COLUMN can_create_own_sponsorships TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_sponsoring;"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_create_own_sponsorships;");
    }
}
