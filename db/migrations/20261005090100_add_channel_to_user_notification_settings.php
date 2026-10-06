<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Einstellungen gelten künftig je Kanal (Mail, Glocke).
 *
 * Alle bestehenden Zeilen sind Entscheidungen über die Mail - eine Glocke gab
 * es noch nicht. Sie bekommen deshalb `mail`; wer Mails abbestellt hatte, sieht
 * die Glocke trotzdem, wie für alle anderen vorgegeben.
 *
 * Der Fremdschlüssel auf `users` stützt sich auf den Primärschlüssel (dessen
 * erste Spalte `user_id` ist). Damit MySQL den Primärschlüssel austauschen
 * lässt, bekommt `user_id` für die Dauer des Tauschs einen eigenen Index.
 */
final class AddChannelToUserNotificationSettings extends AbstractMigration
{
    private const USER_INDEX = 'idx_user_notification_settings_user';

    public function up(): void
    {
        $this->table('user_notification_settings')
            ->addColumn('channel', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'mail',
                'after' => 'notification_type',
            ])
            ->addIndex(['user_id'], ['name' => self::USER_INDEX])
            ->update();

        $this->execute(
            'ALTER TABLE user_notification_settings DROP PRIMARY KEY, '
            . 'ADD PRIMARY KEY (user_id, notification_type, channel)'
        );

        // Der neue Schlüssel beginnt wieder mit `user_id` und trägt den
        // Fremdschlüssel; der Hilfsindex hätte danach nur noch Platz gekostet.
        $this->table('user_notification_settings')
            ->removeIndexByName(self::USER_INDEX)
            ->update();
    }

    public function down(): void
    {
        // Glocken-Zeilen haben im alten Schlüssel keinen Platz - sie würden mit
        // den Mail-Zeilen desselben Anlasses kollidieren.
        $this->execute("DELETE FROM user_notification_settings WHERE channel <> 'mail'");
        $this->table('user_notification_settings')
            ->addIndex(['user_id'], ['name' => self::USER_INDEX])
            ->update();
        $this->execute(
            'ALTER TABLE user_notification_settings DROP PRIMARY KEY, '
            . 'ADD PRIMARY KEY (user_id, notification_type)'
        );
        $this->table('user_notification_settings')
            ->removeIndexByName(self::USER_INDEX)
            ->removeColumn('channel')
            ->update();
    }
}
