<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Der Autorisierungscode merkt sich, wann sich die Person angemeldet hat.
 *
 * Das Anmeldezeugnis trägt die Angabe als `auth_time` nach außen. Bisher stand
 * dort der Zeitpunkt, an dem das Zeugnis ausgestellt wurde - also immer "gerade
 * eben", auch bei einer Sitzung, die über "Angemeldet bleiben" seit Wochen
 * läuft. Eine angeschlossene Anwendung, die nach einer Frist eine neue Anmeldung
 * verlangt, war damit nicht zu bedienen.
 *
 * Die Angabe muss im Code mitreisen, und sie kann nicht aus der Sitzung kommen:
 * Den Code tauscht die fremde Anwendung ohne Browser und ohne Sitzung ein. Der
 * Authorize-Schritt hat die Sitzung noch, der Token-Schritt nicht - deshalb
 * diese Spalte.
 *
 * Nullable, und zwar dauerhaft: Eine Sitzung, die schon vor diesem Ausbau lief,
 * kennt ihre Anmeldezeit nicht. Für sie bleibt die Spalte leer, und das Zeugnis
 * lässt `auth_time` weg - eine fehlende Angabe ist ehrlich, eine geratene nicht.
 */
final class AddAuthenticatedAtToOidcAuthCodes extends AbstractMigration
{
    public function up(): void
    {
        $this->table('oidc_auth_codes')
            ->addColumn('authenticated_at', 'datetime', [
                'null' => true,
                'after' => 'created_at',
                // Der Text trägt bewusst kein Semikolon, auch nicht in einem
                // Kommentar daneben: MigrationChainCompletionTest zerlegt die Datei
                // an diesem Zeichen und schnitte die Kette sonst vor dem update()
                // ab. Die Prüfung irrt dann zu streng, nicht zu lax - sie meldet
                // eine unvollständige Kette, die vollständig ist.
                'comment' => 'Anmeldezeit der Sitzung, aus der dieser Code stammt - leer bei Altsitzungen',
            ])
            ->update();
    }

    /**
     * Ohne Zählprüfung: Die Spalte hat dieses `up()` selbst angelegt, und ihre
     * Werte sind nirgends sonst nötig. Ein Code lebt 60 Sekunden - was hier
     * steht, ist nach einer Minute ohnehin Geschichte, und das Zeugnis daraus
     * ist nach fünf Minuten abgelaufen.
     */
    public function down(): void
    {
        $this->table('oidc_auth_codes')
            ->removeColumn('authenticated_at')
            ->update();
    }
}
