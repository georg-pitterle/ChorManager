<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MigrateSongsToProjectAssignments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'INSERT INTO project_song_assignments (project_id, song_id, note, created_at)
            SELECT project_id, id AS song_id, NULL AS note, created_at FROM songs WHERE project_id IS NOT NULL
            ON DUPLICATE KEY UPDATE note = note'
        );
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Irreversible migration: project_song_assignments backfill cannot be safely rolled back.'
        );
    }
}
