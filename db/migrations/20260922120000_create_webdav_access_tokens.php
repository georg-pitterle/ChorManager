<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zugangstoken für den schreibgeschützten WebDAV-Ordner der Noten.
 *
 * Gespeichert wird nur der SHA-256-Hash, wie bei calendar_subscription_tokens:
 * Der Token ist ein Zufallswert mit 256 Bit Entropie und wandert in eine
 * Noten-App auf einem Tablet, die ihn dauerhaft aufbewahrt. Der Klartext
 * verlässt WebdavAccessService genau einmal, beim Erzeugen.
 *
 * `last_used_at` beantwortet die einzige Frage, die sich beim Widerrufen
 * stellt: Hängt an diesem Token überhaupt noch ein Gerät?
 */
final class CreateWebdavAccessTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->table('webdav_access_tokens')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addIndex(['user_id'])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_webdav_access_tokens_user',
            ])
            ->create();
    }

    public function down(): void
    {
        // drop() reiht die Aktion nur ein, ausgeführt wird sie erst durch save().
        $this->table('webdav_access_tokens')->drop()->save();
    }
}
