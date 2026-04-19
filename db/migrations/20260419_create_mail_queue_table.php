<?php
use Phinx\Migration\AbstractMigration;

class CreateMailQueueTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('mail_queue', ['id' => false, 'primary_key' => ['id']]);
        
        $table
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('mail_type', 'enum', ['values' => ['newsletter', 'invitation', 'password_reset']])
            ->addColumn('recipient_email', 'string', ['limit' => 254])
            ->addColumn('subject', 'string', ['limit' => 255])
            ->addColumn('body_html', 'text', ['limit' => 16777215])
            ->addColumn('payload_json', 'text', ['null' => true])
            ->addColumn('status', 'enum', ['values' => ['queued', 'sending', 'sent', 'failed', 'dead'], 'default' => 'queued'])
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
}
