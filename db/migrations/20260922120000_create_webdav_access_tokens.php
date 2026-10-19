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
        // Prüfung vor dem Löschen: Die Tabelle ist erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - der kam im Betrieb dazu und steht
        // nirgends sonst. Ein Rückbau nähme ihn ohne Nachfrage mit.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM webdav_access_tokens'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: webdav_access_tokens enthält %d Zeile(n), die mit der Tabelle '
                    . 'unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        // drop() reiht die Aktion nur ein, ausgeführt wird sie erst durch save().
        $this->table('webdav_access_tokens')->drop()->save();
    }
}
