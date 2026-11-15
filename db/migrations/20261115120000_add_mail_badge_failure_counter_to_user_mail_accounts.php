<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ein Zähler für aufeinanderfolgende erfolglose Badge-Abgleiche.
 *
 * Bisher war die Wartezeit zwischen zwei Abgleichen fest: fünf Minuten, und bei
 * einem Fehlschlag setzte die MailBadgeRefreshMiddleware den Zeitstempel
 * trotzdem - der nächste Versuch kam also verlässlich in fünf Minuten, auch bei
 * falschem Passwort oder totem Server. Jeder dieser Versuche belegt bis zur
 * Verbindungszeitüberschreitung einen der fünf PHP-FPM-Prozesse.
 *
 * Mit diesem Zähler wächst die Wartezeit: fünf Minuten, ab dem zweiten
 * Fehlschlag eine halbe Stunde, ab dem fünften zwei Stunden. Ein geglückter
 * Abgleich und das Speichern der Mailbox-Einstellungen setzen ihn zurück.
 *
 * Vorgabe 0, denn genau das ist der Stand eines Postfachs, über das noch nichts
 * bekannt ist - bestehende Zeilen starten damit unverändert bei fünf Minuten.
 */
final class AddMailBadgeFailureCounterToUserMailAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_mail_accounts')
            ->addColumn('mail_consecutive_failures', 'integer', [
                'null' => false,
                'default' => 0,
                'signed' => false,
                'after' => 'mail_last_checked_at',
            ])
            ->update();
    }

    public function down(): void
    {
        // Ausgenommen von der Zählpflicht: Die Spalte hat das up() dieser Migration
        // selbst angelegt, und ihr Inhalt ist ein reiner Wartezeit-Zähler ohne
        // eingetragenen Wert - ohne ihn gilt wieder die feste Wartezeit von fünf
        // Minuten, so wie vor dieser Migration.
        $this->table('user_mail_accounts')
            ->removeColumn('mail_consecutive_failures')
            ->update();
    }
}
