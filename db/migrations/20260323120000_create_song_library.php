<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSongLibrary extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_song_library tinyint(1) NOT NULL DEFAULT 0;");

        $this->execute("CREATE TABLE IF NOT EXISTS songs (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            title varchar(255) NOT NULL,
            composer varchar(255) DEFAULT NULL,
            arranger varchar(255) DEFAULT NULL,
            publisher varchar(255) DEFAULT NULL,
            created_by_user_id int(11) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY created_by_user_id (created_by_user_id),
            CONSTRAINT songs_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT songs_ibfk_2 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS song_attachments (
            id int(11) NOT NULL AUTO_INCREMENT,
            song_id int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            mime_type varchar(150) NOT NULL,
            file_size int(11) UNSIGNED NOT NULL DEFAULT 0,
            file_content longblob NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY song_id (song_id),
            CONSTRAINT song_attachments_ibfk_1 FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("UPDATE roles SET can_manage_song_library = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung');");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS song_attachments;');
        $this->execute('DROP TABLE IF EXISTS songs;');
        $this->execute('ALTER TABLE roles DROP COLUMN can_manage_song_library;');
    }
}
