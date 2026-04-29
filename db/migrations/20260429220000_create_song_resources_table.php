<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSongResourcesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS song_resources (
            id int(11) NOT NULL AUTO_INCREMENT,
            song_id int(11) NOT NULL,
            resource_type varchar(32) NOT NULL,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            url varchar(2048) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY song_resources_song_id_idx (song_id),
            KEY song_resources_song_type_title_idx (song_id, resource_type, title),
            CONSTRAINT song_resources_song_fk FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS song_resources');
    }
}
