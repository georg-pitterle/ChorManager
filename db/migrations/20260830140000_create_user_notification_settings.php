<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Wer welche Benachrichtigung bekommen will.
 *
 * Gespeichert wird nur die **Abweichung** von der Vorgabe des Anlasses: Keine
 * Zeile heißt "so wie vorgesehen". Der Grund ist der Nachtrag - bei 140
 * Mitgliedern und neun Anlässen stünden hier sonst über tausend Zeilen, die
 * alle dasselbe sagen, und jeder neue Anlass bräuchte eine Migration, die sie
 * für alle Bestandsmitglieder nachzieht.
 *
 * `enabled` bleibt trotzdem eine echte Spalte und nicht bloß die Anwesenheit
 * der Zeile: Ändert sich später die Vorgabe eines Anlasses, bleibt eine bewusst
 * gesetzte Entscheidung erhalten.
 */
final class CreateUserNotificationSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_notification_settings', [
            'id' => false,
            'primary_key' => ['user_id', 'notification_type'],
        ])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('notification_type', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - der kam im Betrieb dazu und steht
        // nirgends sonst. Ein Rückbau nähme ihn ohne Nachfrage mit.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM user_notification_settings'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: user_notification_settings enthält %d Zeile(n), die mit der Tabelle '
                    . 'unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('user_notification_settings')->drop()->save();
    }
}
