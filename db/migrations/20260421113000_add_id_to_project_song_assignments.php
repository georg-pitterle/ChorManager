<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIdToProjectSongAssignments extends AbstractMigration
{
    public function up(): void
    {
        $hasIdColumn = $this->fetchRow("SHOW COLUMNS FROM project_song_assignments LIKE 'id'");
        if ($hasIdColumn) {
            return;
        }

        $this->execute('ALTER TABLE project_song_assignments DROP PRIMARY KEY');
        $this->execute('ALTER TABLE project_song_assignments ADD COLUMN id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        $this->execute('ALTER TABLE project_song_assignments ADD UNIQUE KEY project_song_unique (project_id, song_id)');
    }

    public function down(): void
    {
        $hasIdColumn = $this->fetchRow("SHOW COLUMNS FROM project_song_assignments LIKE 'id'");
        if (!$hasIdColumn) {
            return;
        }

        $this->execute('ALTER TABLE project_song_assignments DROP INDEX project_song_unique');
        $this->execute('ALTER TABLE project_song_assignments DROP PRIMARY KEY');
        $this->execute('ALTER TABLE project_song_assignments DROP COLUMN id');
        $this->execute('ALTER TABLE project_song_assignments ADD PRIMARY KEY (project_id, song_id)');
    }
}
