<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddDeliveryLifecycleToMailQueue extends AbstractMigration
{
    public function change(): void
    {
        $this->table('mail_queue')
            ->addColumn('delivery_status', 'enum', [
                'values' => ['pending', 'accepted', 'delivered', 'bounced', 'complained', 'skipped'],
                'default' => 'pending',
                'after' => 'status',
            ])
            ->addColumn('provider_name', 'string', ['limit' => 50, 'null' => true, 'after' => 'delivery_status'])
            ->addColumn('provider_message_id', 'string', ['limit' => 190, 'null' => true, 'after' => 'provider_name'])
            ->addColumn('accepted_at', 'datetime', ['null' => true, 'after' => 'sent_at'])
            ->addColumn('delivered_at', 'datetime', ['null' => true, 'after' => 'accepted_at'])
            ->addColumn('bounced_at', 'datetime', ['null' => true, 'after' => 'delivered_at'])
            ->addColumn('complained_at', 'datetime', ['null' => true, 'after' => 'bounced_at'])
            ->addColumn('last_event_at', 'datetime', ['null' => true, 'after' => 'complained_at'])
            ->addColumn('last_event_type', 'string', ['limit' => 80, 'null' => true, 'after' => 'last_event_at'])
            ->addIndex(['delivery_status'])
            ->addIndex(['provider_message_id'])
            ->update();
    }
}
