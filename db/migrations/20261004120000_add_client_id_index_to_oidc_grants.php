<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gibt den beiden OIDC-Tabellen einen Index auf client_id.
 *
 * `oidc_auth_codes.client_id` und `oidc_access_tokens.client_id` tragen die
 * Kennung der angeschlossenen Anwendung, standen aber als einzige Spalte mit
 * einem Bezug ohne Index da. Jede Frage nach "alles, was zu diesem Client
 * gehört" las damit die ganze Tabelle - und genau die stellt sich dort, wo es
 * darauf ankommt: beim Abschalten eines Clients und beim Aufräumen seiner
 * Reste.
 *
 * Seit AccessTokenService::resolve() den Client mitprüft, hängt daran auch der
 * laufende Betrieb: Die Prüfung schlägt bei jedem Aufruf von /oidc/userinfo
 * nach. Das ist ein Zugriff über oidc_clients.client_id (dort eindeutig
 * indiziert), der Index hier trägt die Gegenrichtung.
 *
 * Ein Fremdschlüssel auf oidc_clients.client_id kommt bewusst nicht dazu. Ein
 * Autorisierungscode und ein Zugriffstoken halten fest, was ausgestellt wurde;
 * mit CASCADE verschwänden sie beim Löschen eines Clients spurlos, mit
 * RESTRICT ließe sich ein Client nicht mehr entfernen, solange auch nur ein
 * abgelaufenes Token von ihm herumliegt. Beides ist schlechter als die heutige
 * Lage, in der AuthorizationCodeService::pruneExpired() die Zeilen nach Ablauf
 * ohnehin abräumt und resolve() einen Treffer ohne gültigen Client verwirft.
 */
final class AddClientIdIndexToOidcGrants extends AbstractMigration
{
    /** Tabelle => Indexname */
    private const INDEXES = [
        'oidc_auth_codes' => 'idx_oidc_auth_codes_client_id',
        'oidc_access_tokens' => 'idx_oidc_access_tokens_client_id',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexName) {
            $this->table($table)
                ->addIndex(['client_id'], ['name' => $indexName])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexName) {
            $this->table($table)
                ->removeIndexByName($indexName)
                ->update();
        }
    }
}
