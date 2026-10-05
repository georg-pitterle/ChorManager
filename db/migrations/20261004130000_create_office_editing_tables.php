<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Office-Bearbeitung über Collabora (WOPI).
 *
 * `office_access_tokens`: Zugangstokens, mit denen Collabora Dateien holt und
 * speichert. Gespeichert wird nur der SHA-256-Hash.
 *
 * `file_versions.office_session_open` / `office_saved_at`: Eine Bearbeitungssitzung
 * ergibt eine Version. Solange die Sitzung offen ist, ersetzt jedes Speichern den
 * Inhalt dieser Version (unter neuem Pfad).
 */
final class CreateOfficeEditingTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('office_access_tokens')
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('file_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_office_access_tokens_hash'])
            ->addIndex(['expires_at'])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_office_access_tokens_file',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_office_access_tokens_user',
            ])
            ->create();

        $this->table('file_versions')
            ->addColumn('office_session_open', 'boolean', ['null' => false, 'default' => false, 'after' => 'uploaded_by'])
            ->addColumn('office_saved_at', 'datetime', ['null' => true, 'after' => 'office_session_open'])
            ->update();
    }
}
