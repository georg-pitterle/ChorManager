<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * calendar_subscription_tokens war die einzige benutzergebundene Token-Tabelle
 * ohne Fremdschlüssel. Ein gelöschtes Mitglied hinterließ seine Zeile; der Feed
 * lief dadurch zwar auf 404 (der Controller sucht den Benutzer), die Tabelle
 * sammelte aber dauerhaft Karteileichen. invitation_tokens, remember_logins und
 * user_mail_accounts machen es seit jeher mit ON DELETE CASCADE.
 */
final class AddUserForeignKeyToCalendarSubscriptionTokens extends AbstractMigration
{
    private const CONSTRAINT = 'fk_calendar_subscription_tokens_user';

    public function up(): void
    {
        // Bestehende Karteileichen müssen weg, bevor der Constraint greifen kann -
        // sonst scheitert das ALTER an genau den Zeilen, die es aufräumen soll.
        $orphaned = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS orphaned
             FROM calendar_subscription_tokens t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE u.id IS NULL'
        )['orphaned'] ?? 0);

        if ($orphaned > 0) {
            $this->execute(
                'DELETE t
                 FROM calendar_subscription_tokens t
                 LEFT JOIN users u ON u.id = t.user_id
                 WHERE u.id IS NULL'
            );
        }

        $this->table('calendar_subscription_tokens')
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => self::CONSTRAINT,
            ])
            ->update();
    }

    public function down(): void
    {
        // Die im up() entfernten verwaisten Zeilen kommen nicht zurück - sie
        // verwiesen auf gelöschte Mitglieder und waren schon vorher wertlos.
        $this->table('calendar_subscription_tokens')
            ->dropForeignKey('user_id', self::CONSTRAINT)
            ->update();
    }
}
