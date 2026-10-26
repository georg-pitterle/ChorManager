<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Warteschlange des Mailversands.
 *
 * `up()` und `down()` statt `change()`: Phinx spielt ein `change()` beim
 * Rückbau verkehrt herum nach und erzeugt aus dem `create()` selbst ein
 * `drop()`. Davor lässt sich keine Zählabfrage setzen - noch nicht
 * ausgelieferte Mails wären still weg, und die ausgelieferten mit ihnen: Die
 * Tabelle ist der einzige Nachweis, wer wann welche Mail bekommen hat.
 * `/phinx-migration` verlangt die Prüfung ausdrücklich auch im eigenen
 * `down()`.
 */
final class CreateMailQueueTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('mail_queue', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('mail_type', 'enum', ['values' => ['newsletter', 'invitation', 'password_reset']])
            ->addColumn('recipient_email', 'string', ['limit' => 254])
            ->addColumn('subject', 'string', ['limit' => 255])
            ->addColumn('body_html', 'text', ['limit' => 16777215])
            ->addColumn('payload_json', 'text', ['null' => true])
            ->addColumn('status', 'enum', [
                'values' => ['queued', 'sending', 'sent', 'failed', 'dead'],
                'default' => 'queued',
            ])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('max_attempts', 'integer', ['default' => 3])
            ->addColumn('next_attempt_at', 'datetime', ['null' => true])
            ->addColumn('last_attempt_at', 'datetime', ['null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addColumn('error_code', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('is_retryable', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['status'])
            ->addIndex(['next_attempt_at'])
            ->addIndex(['created_at'])
            ->addIndex(['mail_type'])
            ->create();
    }

    public function down(): void
    {
        // Prüfung vor dem Löschen: Die Tabelle ist mit dieser Migration
        // entstanden, ihr Inhalt aber im Betrieb und nirgends sonst. Eine
        // frische Installation ist leer und wird nicht aufgehalten.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM mail_queue'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: mail_queue enthält %d Mail(s) - offene wie ausgelieferte -, die '
                    . 'mit der Tabelle unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        $this->table('mail_queue')->drop()->update();
    }
}
