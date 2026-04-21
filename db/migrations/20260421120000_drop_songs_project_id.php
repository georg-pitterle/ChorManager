<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropSongsProjectId extends AbstractMigration
{
    public function up(): void
    {
        $missingAssignments = $this->fetchRow(
            'SELECT COUNT(*) AS count
            FROM songs s
            LEFT JOIN project_song_assignments psa
                ON psa.song_id = s.id
                AND psa.project_id = s.project_id
            WHERE s.project_id IS NOT NULL
              AND psa.song_id IS NULL'
        );

        if (($missingAssignments['count'] ?? 0) > 0) {
            throw new \RuntimeException(
                'Cannot drop songs.project_id: backfill incomplete for one or more songs.'
            );
        }

        $foreignKeyExists = $this->fetchRow(
            "SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'songs'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = 'songs_ibfk_1'"
        );

        if ($foreignKeyExists) {
            $this->execute('ALTER TABLE songs DROP FOREIGN KEY songs_ibfk_1');
        }

        $this->execute('ALTER TABLE songs DROP COLUMN project_id');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE songs ADD COLUMN project_id int(11) DEFAULT NULL');
        $this->execute('ALTER TABLE songs ADD INDEX project_id (project_id)');
        $this->execute('ALTER TABLE songs ADD CONSTRAINT songs_ibfk_1
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
    }
}
