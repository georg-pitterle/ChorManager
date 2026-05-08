<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSheetArchiveTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS sheet_archives (
            id int(11) NOT NULL AUTO_INCREMENT,
            song_id int(11) NOT NULL,
            archive_number varchar(100) DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY sheet_archives_song_id_unique (song_id),
            CONSTRAINT sheet_archives_song_fk FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS sheet_archive_line_items (
            id int(11) NOT NULL AUTO_INCREMENT,
            sheet_archive_id int(11) NOT NULL,
            voice_category varchar(100) NOT NULL,
            count int(11) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY sheet_archive_line_items_archive_id_idx (sheet_archive_id),
            CONSTRAINT sheet_archive_line_items_archive_fk FOREIGN KEY (sheet_archive_id) REFERENCES sheet_archives (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS sheet_archive_line_items');
        $this->execute('DROP TABLE IF EXISTS sheet_archives');
    }
}
