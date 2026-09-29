<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Freigaben auf einzelne Dateien und öffentliche Links.
 *
 * file_shares: wie file_folder_shares, aber für eine Datei und nur mit den
 * Stufen Lesen (1) und Bearbeiten (3). Eine Dateifreigabe erweitert die Stufe
 * aus dem Ordner, sie schränkt nie ein.
 *
 * file_public_links: Link für Menschen ohne Konto. Gespeichert wird nur der
 * SHA-256-Hash des Tokens, wie bei webdav_access_tokens; der Klartext wird
 * genau einmal nach dem Anlegen angezeigt.
 */
final class CreateFileSharesAndPublicLinks extends AbstractMigration
{
    public function up(): void
    {
        $this->table('file_shares')
            ->addColumn('file_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('target_type', 'enum', [
                'values' => ['role', 'user', 'voice_group', 'project_members', 'all_members'],
                'null' => false,
            ])
            ->addColumn('reference_id', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('level', 'integer', ['limit' => 255, 'null' => false])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['file_id', 'target_type', 'reference_id'], [
                'unique' => true,
                'name' => 'uniq_file_shares_target',
            ])
            ->addIndex(['target_type', 'reference_id'])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_shares_file',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_shares_created_by',
            ])
            ->create();

        $this->table('file_public_links')
            ->addColumn('file_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('download_count', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['file_id'])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_public_links_file',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_public_links_created_by',
            ])
            ->create();
    }

    public function down(): void
    {
        // drop() reiht die Aktion nur ein, ausgeführt wird sie erst durch save().
        $this->table('file_public_links')->drop()->save();
        $this->table('file_shares')->drop()->save();
    }
}
