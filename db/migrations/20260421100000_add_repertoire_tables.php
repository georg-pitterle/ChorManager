<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRepertoireTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE songs DROP FOREIGN KEY songs_ibfk_1');
        $this->execute('ALTER TABLE songs MODIFY COLUMN project_id int(11) DEFAULT NULL');
        $this->execute('ALTER TABLE songs ADD CONSTRAINT songs_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');

        $this->execute("CREATE TABLE IF NOT EXISTS repertoire_categories (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY repertoire_categories_name_unique (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS song_category_assignments (
            song_id int(11) NOT NULL,
            repertoire_category_id int(11) NOT NULL,
            PRIMARY KEY (song_id, repertoire_category_id),
            KEY song_category_assignments_category_id_idx (repertoire_category_id),
            CONSTRAINT song_category_assignments_song_fk FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE,
            CONSTRAINT song_category_assignments_category_fk FOREIGN KEY (repertoire_category_id) REFERENCES repertoire_categories (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS project_song_assignments (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            song_id int(11) NOT NULL,
            note varchar(1000) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY project_song_unique (project_id, song_id),
            KEY project_song_assignments_song_id_idx (song_id),
            CONSTRAINT project_song_assignments_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT project_song_assignments_song_fk FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS project_song_assignments');
        $this->execute('DROP TABLE IF EXISTS song_category_assignments');
        $this->execute('DROP TABLE IF EXISTS repertoire_categories');

        $nullProjectRows = (int) ($this->fetchRow('SELECT COUNT(*) AS count FROM songs WHERE project_id IS NULL')['count'] ?? 0);
        if ($nullProjectRows > 0) {
            throw new \RuntimeException(
                'Rollback blocked: songs.project_id contains NULL values. Reassign NULL rows before migrating down.'
            );
        }

        $this->execute('ALTER TABLE songs DROP FOREIGN KEY songs_ibfk_1');
        $this->execute('ALTER TABLE songs MODIFY COLUMN project_id int(11) NOT NULL');
        $this->execute('ALTER TABLE songs ADD CONSTRAINT songs_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
    }
}
