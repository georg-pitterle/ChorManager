<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Terminarten können hervorgehoben werden.
 *
 * Woran ein Termin wichtig ist, weiß das System nicht von selbst; Proben
 * und Konzerte stünden sonst gleichförmig untereinander. Die Entscheidung
 * fällt einmal pro Terminart in den Einstellungen und gilt für alle ihre
 * Termine. Bestehende Arten starten unauffällig.
 */
final class AddIsHighlightedToEventTypes extends AbstractMigration
{
    public function change(): void
    {
        $this->table('event_types')
            ->addColumn('is_highlighted', 'boolean', ['default' => false, 'null' => false, 'after' => 'color'])
            ->update();
    }
}
