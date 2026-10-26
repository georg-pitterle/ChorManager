<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Einladungstokens: der Link, über den ein neues Mitglied sein Passwort setzt.
 *
 * `up()` und `down()` statt `change()`: Phinx spielt ein `change()` beim
 * Rückbau verkehrt herum nach und erzeugt aus dem `create()` selbst ein
 * `drop()`. Davor lässt sich keine Zählabfrage setzen, und offene Einladungen
 * wären still weg - niemand käme mehr über seinen Link herein, und neu
 * verschicken kann man sie nur von Hand. `/phinx-migration` verlangt die
 * Prüfung ausdrücklich auch im eigenen `down()`.
 */
final class CreateInvitationTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->table('invitation_tokens')
            ->addColumn('user_id', 'integer', ['signed' => true])
            ->addColumn('selector', 'string', ['limit' => 64])
            ->addColumn('token_hash', 'string', ['limit' => 255])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['selector'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist mit dieser Migration
        // entstanden, ihr Inhalt aber im Betrieb - jede Zeile ist eine offene
        // Einladung und steht nirgends sonst. Eine frische Installation ist
        // leer und wird von der Prüfung nicht aufgehalten.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM invitation_tokens'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: invitation_tokens enthält %d offene Einladung(en), die mit der '
                    . 'Tabelle unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('invitation_tokens')->drop()->update();
    }
}
