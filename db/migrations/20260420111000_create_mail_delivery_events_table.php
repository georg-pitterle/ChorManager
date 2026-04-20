<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMailDeliveryEventsTable extends AbstractMigration
{
    public function change(): void
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
}
