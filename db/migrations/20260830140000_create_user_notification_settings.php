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
        $this->table('user_notification_settings')->drop()->save();
    }
}
