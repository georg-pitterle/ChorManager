<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entfernt roles.can_manage_attendance.
 *
 * Das Recht hatte keinen eigenen Umfang mehr: AttendanceScopeService schränkte
 * jeden ohne can_manage_attendance_all auf die eigenen Stimmgruppen ein - also
 * auf genau den Umfang von can_manage_own_voice_group. Wer in keiner
 * Stimmgruppe stand, verwaltete damit niemanden. Es bleiben die eigene
 * Stimmgruppe (can_manage_own_voice_group) und alle (can_manage_attendance_all).
 *
 * Der Wert geht nicht verloren, er zieht um: Jede Rolle mit
 * can_manage_attendance bekommt can_manage_own_voice_group, dessen Beschreibung
 * in der Rollenverwaltung ohnehin schon "Anwesenheit und Anmeldungen ihrer
 * eigenen Stimmgruppe verwalten (Stimmvertretung)" lautet.
 */
final class DropCanManageAttendanceFromRoles extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->columnExists('can_manage_attendance')) {
            return;
        }

        // Umschreiben vor dem Löschen, sonst ist der Wert weg.
        $this->execute(
            'UPDATE roles
             SET can_manage_own_voice_group = 1
             WHERE can_manage_attendance = 1'
        );

        // Prüfung steht vor dem DROP: Greift sie erst danach, sind die Daten
        // schon fort und der Lauf endet auf halbem Weg.
        $notMigrated = $this->fetchRow(
            'SELECT COUNT(*) AS count
             FROM roles
             WHERE can_manage_attendance = 1
               AND can_manage_own_voice_group <> 1'
        );

        if ((int) ($notMigrated['count'] ?? 0) > 0) {
            throw new \RuntimeException(
                'Cannot drop roles.can_manage_attendance: backfill to can_manage_own_voice_group incomplete.'
            );
        }

        $this->execute('ALTER TABLE roles DROP COLUMN can_manage_attendance');
    }

    public function down(): void
    {
        if ($this->columnExists('can_manage_attendance')) {
            return;
        }

        $this->execute(
            'ALTER TABLE roles
             ADD COLUMN can_manage_attendance tinyint(1) NOT NULL DEFAULT 0
             AFTER can_edit_users'
        );

        // Der Wert steht seit up() in can_manage_own_voice_group; von dort kommt
        // er zurück. Welche Rolle das Recht ursprünglich einzeln trug, weiß die
        // Datenbank nicht mehr - die Umschreibung ist nur in eine Richtung genau.
        $this->execute(
            'UPDATE roles
             SET can_manage_attendance = 1
             WHERE can_manage_own_voice_group = 1'
        );
    }

    private function columnExists(string $column): bool
    {
        $row = $this->fetchRow(
            sprintf(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'roles'
                   AND COLUMN_NAME = '%s'",
                $column
            )
        );

        return $row !== false && $row !== null && $row !== [];
    }
}
