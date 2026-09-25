<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Das Schema des OpenID-Connect-Providers.
 *
 * ChorManager stellt damit Anmeldungen für fremde Anwendungen aus - zunächst
 * für die Nextcloud-Instanz des Chores. Gespeichert wird von jedem Geheimnis
 * nur sein Abbild: Client-Secrets als Passwort-Hash, Autorisierungscodes und
 * Zugriffstoken als SHA-256 ihres Zufallswerts, wie bei
 * calendar_subscription_tokens und webdav_access_tokens. Der Klartext verlässt
 * den jeweiligen Dienst genau einmal.
 *
 * Der private Signierschlüssel liegt verschlüsselt (libsodium-Secretbox,
 * Schlüssel aus OIDC_SIGNING_KEY_SECRET) - ein Datenbank-Abzug allein erlaubt
 * damit noch keine gefälschten Anmeldungen.
 */
final class CreateOidcTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('oidc_clients')
            ->addColumn('client_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('client_secret_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            // JSON-Liste. Verglichen wird immer vollständig und exakt, nie per Präfix:
            // ein Präfixvergleich ließe angehängte Pfade und Query-Teile durch und
            // damit die Umleitung des Codes an einen fremden Endpunkt.
            ->addColumn('redirect_uris', 'text', ['null' => false])
            ->addColumn('post_logout_redirect_uris', 'text', ['null' => true])
            ->addColumn('is_trusted', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('is_active', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id'], ['unique' => true])
            ->create();

        $this->table('oidc_auth_codes')
            ->addColumn('code_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('redirect_uri', 'string', ['limit' => 512, 'null' => false])
            ->addColumn('scope', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('nonce', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('code_challenge', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('code_challenge_method', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            // Gesetzt statt gelöscht: Eine zweite Einlösung desselben Codes ist der
            // klassische Diebstahlsverdacht. Nur wer die Zeile noch findet, kann die
            // daraus ausgestellten Token widerrufen.
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['code_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addIndex(['expires_at'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_oidc_auth_codes_user',
            ])
            ->create();

        $this->table('oidc_access_tokens')
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            // Unsigned, weil Phinx seine `id`-Spalten unsigned anlegt - ein signed
            // Verweis darauf weist MySQL als falsch geformten Fremdschlüssel ab.
            ->addColumn('auth_code_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('scope', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addIndex(['auth_code_id'])
            ->addIndex(['expires_at'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_oidc_access_tokens_user',
            ])
            ->addForeignKey('auth_code_id', 'oidc_auth_codes', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_oidc_access_tokens_auth_code',
            ])
            ->create();

        $this->table('oidc_signing_keys')
            ->addColumn('kid', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('public_key', 'text', ['null' => false])
            ->addColumn('private_key_encrypted', 'text', ['null' => false])
            // Signiert wird stets mit dem aktiven Schlüssel. Der abgelöste bleibt
            // eine Karenzzeit in JWKS stehen, damit ein zwischengespeicherter
            // Schlüsselsatz beim Client nicht sofort bricht.
            ->addColumn('is_active', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['kid'], ['unique' => true])
            ->addIndex(['is_active'])
            ->create();
    }

    public function down(): void
    {
        // drop() reiht die Aktion nur ein, ausgeführt wird sie erst durch save().
        // Reihenfolge nach den Fremdschlüsseln: Token vor Codes.
        $this->table('oidc_access_tokens')->drop()->save();
        $this->table('oidc_auth_codes')->drop()->save();
        $this->table('oidc_signing_keys')->drop()->save();
        $this->table('oidc_clients')->drop()->save();
    }
}
