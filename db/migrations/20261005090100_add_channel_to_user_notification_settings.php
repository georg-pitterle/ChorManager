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

    /**
     * Zählt die Entscheidungen, die im alten Schlüssel keinen Platz haben.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * AllowNewslettersWithoutProject::PROJECTLESS_NEWSLETTERS_SQL.
     */
    public const BELL_SETTINGS_SQL = <<<'SQL'
        SELECT COUNT(*) AS bell_settings
        FROM user_notification_settings
        WHERE channel <> 'mail'
        SQL;

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
        // Prüfung vor dem destruktiven Schritt: Glocken-Zeilen haben im alten
        // Schlüssel keinen Platz - sie würden mit den Mail-Zeilen desselben
        // Anlasses kollidieren. Bisher wurden sie dafür still gelöscht.
        //
        // Das ist der falsche Preis für einen Rückbau: Es sind Entscheidungen,
        // die Mitglieder selbst getroffen haben, und sie stehen nirgends sonst -
        // mit der Spalte sind sie weg, und zurückschreiben ließen sie sich
        // danach nirgendwo. Der Lauf bricht deshalb ab und nennt ihre Zahl; wer
        // wirklich zurück will, entfernt sie vorher bewusst selbst.
        $bellSettings = (int) ($this->fetchRow(self::BELL_SETTINGS_SQL)['bell_settings'] ?? 0);

        if ($bellSettings > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Einstellung(en) betreffen die Glocke. Sie haben im alten '
                    . 'Schlüssel keinen Platz und wären unwiederbringlich weg. Diese zuerst bewusst '
                    . 'selbst entfernen.',
                $bellSettings
            ));
        }

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
