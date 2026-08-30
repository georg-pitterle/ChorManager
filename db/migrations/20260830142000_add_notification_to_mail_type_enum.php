<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ein einziger neuer Wert für alle Benachrichtigungen statt einer je Anlass.
 *
 * Welcher Anlass es war, steht in `payload_json` unter `notification_type`. Neun
 * ENUM-Werte hätten bedeutet, dass jeder weitere Anlass die Spalte erneut
 * umbaut - eine Schemaänderung für etwas, das die Nutzlast ohnehin trägt.
 */
final class AddNotificationToMailTypeEnum extends AbstractMigration
{
    private const WITH_NOTIFICATION = [
        'newsletter',
        'invitation',
        'password_reset',
        'registration_reminder',
        'notification',
    ];

    private const WITHOUT_NOTIFICATION = [
        'newsletter',
        'invitation',
        'password_reset',
        'registration_reminder',
    ];

    public function up(): void
    {
        $this->table('mail_queue')
            ->changeColumn('mail_type', 'enum', ['values' => self::WITH_NOTIFICATION])
            ->update();
    }

    public function down(): void
    {
        // Wie beim Vorgänger 20260719180000 auf 'invitation' zurück: Eine
        // Benachrichtigung ist eine gerichtete Systemmail, keine Rundsendung,
        // und darf nicht in der Newsletter-Abmeldelogik landen.
        $this->execute(
            "UPDATE mail_queue
            SET mail_type = 'invitation'
            WHERE mail_type = 'notification'"
        );

        $this->table('mail_queue')
            ->changeColumn('mail_type', 'enum', ['values' => self::WITHOUT_NOTIFICATION])
            ->update();
    }
}
