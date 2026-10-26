<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Zustellereignisse zu einer Mail: Rückläufer (DSN) und Webhook-Meldungen.
 *
 * `up()` und `down()` statt `change()`: Phinx spielt ein `change()` beim
 * Rückbau verkehrt herum nach und erzeugt aus dem `create()` selbst ein
 * `drop()`. Davor lässt sich keine Zählabfrage setzen - und was hier steht,
 * liefert der Anbieter nicht erneut: Welche Adresse unzustellbar ist, wäre
 * nach dem Rückbau nur noch durch einen neuen Versand herauszufinden.
 * `/phinx-migration` verlangt die Prüfung ausdrücklich auch im eigenen
 * `down()`.
 */
final class CreateMailDeliveryEventsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('mail_delivery_events')
            ->addColumn('mail_queue_id', 'biginteger', ['signed' => false])
            ->addColumn('provider_name', 'string', ['limit' => 50])
            ->addColumn('provider_message_id', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('source_channel', 'enum', ['values' => ['dsn', 'webhook']])
            ->addColumn('event_type_normalized', 'string', ['limit' => 80])
            ->addColumn('event_type_raw', 'string', ['limit' => 120])
            ->addColumn('idempotency_key', 'string', ['limit' => 190])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('received_at', 'datetime')
            ->addColumn('raw_payload', 'text')
            ->addIndex(['mail_queue_id'])
            ->addIndex(['provider_message_id'])
            ->addIndex(['idempotency_key'], ['unique' => true])
            ->addForeignKey(
                'mail_queue_id',
                'mail_queue',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist mit dieser Migration
        // entstanden, ihr Inhalt aber im Betrieb und nirgends sonst. Eine
        // frische Installation ist leer und wird nicht aufgehalten.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM mail_delivery_events'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: mail_delivery_events enthält %d Zustellereignis(se), die mit der '
                    . 'Tabelle unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('mail_delivery_events')->drop()->update();
    }
}
