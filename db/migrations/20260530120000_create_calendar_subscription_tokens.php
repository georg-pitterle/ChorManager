<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCalendarSubscriptionTokens extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('calendar_subscription_tokens');
        $table->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            ->addIndex(['token'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('calendar_subscription_tokens')->drop();
    }
}
