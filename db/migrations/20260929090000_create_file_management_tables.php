<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabellen der Dateiverwaltung (Teamordner).
 *
 * Die Dateien selbst liegen nicht in der Datenbank, sondern über die
 * Storage-Naht auf der Platte; `file_versions.storage_path` zeigt dorthin.
 * Jede Version bekommt einen eigenen Pfad und wird nie überschrieben - darauf
 * baut die inkrementelle Dateisicherung im Backup.
 *
 * Eindeutige Namen je Ordner erzwingt der Service: Mit `deleted_at` als
 * NULL-Spalte ließe sich ein UNIQUE-Index in MySQL nicht sinnvoll bilden,
 * weil Papierkorbeinträge denselben Namen tragen dürfen.
 */
final class CreateFileManagementTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('file_folders')
            ->addColumn('parent_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('quota_bytes', 'biginteger', ['null' => true])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('deleted_by', 'integer', ['null' => true])
            ->addIndex(['parent_id', 'name'])
            ->addIndex(['deleted_at'])
            ->addForeignKey('parent_id', 'file_folders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_folders_parent',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_folders_created_by',
            ])
            ->addForeignKey('deleted_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_folders_deleted_by',
            ])
            ->create();

        $this->table('files')
            ->addColumn('folder_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('current_version_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('size', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('mime_type', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('updated_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('deleted_by', 'integer', ['null' => true])
            ->addIndex(['folder_id', 'name'])
            ->addIndex(['name'])
            ->addIndex(['deleted_at'])
            ->addForeignKey('folder_id', 'file_folders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_files_folder',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_files_created_by',
            ])
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_files_updated_by',
            ])
            ->addForeignKey('deleted_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_files_deleted_by',
            ])
            ->create();

        $this->table('file_versions')
            ->addColumn('file_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('version_number', 'integer', ['null' => false])
            ->addColumn('storage_driver', 'string', ['limit' => 20, 'null' => false, 'default' => 'local'])
            ->addColumn('storage_path', 'string', ['limit' => 512, 'null' => false])
            ->addColumn('size', 'biginteger', ['null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('sha256', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('uploaded_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['file_id', 'version_number'], ['unique' => true])
            ->addIndex(['storage_path'])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_versions_file',
            ])
            ->addForeignKey('uploaded_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_versions_uploaded_by',
            ])
            ->create();

        // Erst jetzt möglich: files und file_versions verweisen gegenseitig aufeinander.
        $this->table('files')
            ->addForeignKey('current_version_id', 'file_versions', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_files_current_version',
            ])
            ->update();

        $this->table('file_folder_shares')
            ->addColumn('folder_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('target_type', 'enum', [
                'values' => ['role', 'user', 'voice_group', 'project_members', 'all_members'],
                'null' => false,
            ])
            // Bei all_members 0 statt NULL, damit der UNIQUE-Index auch dort greift.
            ->addColumn('reference_id', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('level', 'integer', ['limit' => 255, 'null' => false])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['folder_id', 'target_type', 'reference_id'], [
                'unique' => true,
                'name' => 'uniq_file_folder_shares_target',
            ])
            ->addIndex(['target_type', 'reference_id'])
            ->addForeignKey('folder_id', 'file_folders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_folder_shares_folder',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_folder_shares_created_by',
            ])
            ->create();

        $this->table('file_favorites')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('file_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('folder_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'file_id'], ['unique' => true, 'name' => 'uniq_file_favorites_file'])
            ->addIndex(['user_id', 'folder_id'], ['unique' => true, 'name' => 'uniq_file_favorites_folder'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_favorites_user',
            ])
            ->addForeignKey('file_id', 'files', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_favorites_file',
            ])
            ->addForeignKey('folder_id', 'file_folders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_file_favorites_folder',
            ])
            ->create();
    }

    public function down(): void
    {
        // Die gegenseitige Verknüpfung zuerst lösen, sonst lässt sich keine der
        // beiden Tabellen entfernen. Die Dateien auf der Platte bleiben liegen;
        // sie gehören nicht zur Datenbank und werden hier bewusst nicht angefasst.
        $this->table('files')->dropForeignKey('current_version_id')->update();
        $this->table('file_favorites')->drop()->save();
        $this->table('file_folder_shares')->drop()->save();
        $this->table('file_versions')->drop()->save();
        $this->table('files')->drop()->save();
        $this->table('file_folders')->drop()->save();
    }
}
