<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Eine Sitzungsgrenze je Konto.
 *
 * Bisher gab es nur die globale Grenze `app_settings.session_valid_after`, die
 * alle Anmeldungen auf einmal beendet. Ein Passwortwechsel - die übliche
 * Reaktion auf einen Verdacht - verwarf zwar die Angemeldet-bleiben-Token des
 * Kontos, eine bereits laufende Sitzung auf einem fremden Gerät blieb aber
 * angemeldet. Die AuthMiddleware vergleicht diese Spalte mit dem Anmeldezeitpunkt
 * der Sitzung (`auth_epoch`) und meldet ältere Sitzungen ab.
 *
 * Unix-Sekunden wie `auth_epoch`, damit der Vergleich ohne Umrechnung auskommt.
 * NULL heißt: keine Grenze gesetzt.
 */
final class AddSessionsValidAfterToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('sessions_valid_after', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => false,
                'after' => 'is_active',
            ])
            ->update();
    }

    public function down(): void
    {
        // Ausgenommen von der Zählpflicht: Die Spalte hat das up() dieser Migration
        // selbst angelegt, und ihr Wert ist ein reiner Zeitstempel ohne eingetragenen
        // Inhalt - ohne ihn gilt lediglich keine Sitzungsgrenze je Konto mehr.
        $this->table('users')
            ->removeColumn('sessions_valid_after')
            ->update();
    }
}
