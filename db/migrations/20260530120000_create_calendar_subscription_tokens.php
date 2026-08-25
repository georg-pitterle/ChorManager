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
        // drop() reiht die Aktion nur ein, ausgeführt wird sie erst durch save().
        // Ohne den Abschluss meldet der Rollback Erfolg, lässt die Tabelle aber
        // stehen - ein anschließendes migrate scheitert dann daran, dass
        // "calendar_subscription_tokens" bereits existiert.
        $this->table('calendar_subscription_tokens')->drop()->save();
    }
}
