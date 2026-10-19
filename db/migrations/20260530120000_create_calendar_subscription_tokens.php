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
        // Prüfung vor dem Löschen: Die Tabelle ist erst mit dieser Migration
        // entstanden, ihr Inhalt aber nicht - der kam im Betrieb dazu und steht
        // nirgends sonst. Ein Rückbau nähme ihn ohne Nachfrage mit.
        $rowsLeft = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM calendar_subscription_tokens'
        )['rows_left'] ?? 0);

        if ($rowsLeft > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: calendar_subscription_tokens enthält %d Zeile(n), die mit der Tabelle '
                    . 'unwiederbringlich weg wären. Diese zuerst bewusst selbst entfernen.',
                $rowsLeft
            ));
        }

        // drop() reiht die Aktion nur ein, ausgeführt wird sie erst durch save().
        // Ohne den Abschluss meldet der Rollback Erfolg, lässt die Tabelle aber
        // stehen - ein anschließendes migrate scheitert dann daran, dass
        // "calendar_subscription_tokens" bereits existiert.
        $this->table('calendar_subscription_tokens')->drop()->save();
    }
}
