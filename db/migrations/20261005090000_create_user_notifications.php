<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Einträge der Glocke in der Kopfzeile.
 *
 * `link` ist ein relativer Pfad und `entity_type`/`entity_id` nennen das Objekt,
 * auf das er zeigt - darüber gelten alle Einträge zu einer Aufgabe als gelesen,
 * sobald jemand die Aufgabe öffnet, egal auf welchem Weg.
 *
 * `comment_id` nennt die Bemerkung oder den Kommentar, aus dem der Eintrag
 * stammt. Wird sie gelöscht oder geändert, zieht der Eintrag nach - sonst bliebe
 * ein versehentlich geposteter Text in der Glocke aller anderen lesbar. Ohne
 * Fremdschlüssel, weil `comments` polymorph an Termin und Aufgabe hängt und
 * schon heute ohne Kaskade gelöscht wird (EntityCleanupService).
 */
final class CreateUserNotifications extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_notifications')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('notification_type', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('actor_user_id', 'integer', ['null' => true])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('link', 'string', ['limit' => 512, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('entity_id', 'integer', ['null' => true])
            ->addColumn('comment_id', 'integer', ['null' => true])
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'read_at', 'created_at'], ['name' => 'idx_user_notifications_inbox'])
            ->addIndex(['user_id', 'entity_type', 'entity_id'], ['name' => 'idx_user_notifications_entity'])
            ->addIndex(['comment_id'], ['name' => 'idx_user_notifications_comment'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('user_notifications')->drop()->save();
    }
}
