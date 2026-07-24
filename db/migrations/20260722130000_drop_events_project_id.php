<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropEventsProjectId extends AbstractMigration
{
    public function up(): void
    {
        $foreignKey = $this->fetchRow(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'events'
               AND COLUMN_NAME = 'project_id'
               AND REFERENCED_TABLE_NAME = 'projects'
             LIMIT 1"
        );

        if ($foreignKey && !empty($foreignKey['CONSTRAINT_NAME'])) {
            $this->execute('ALTER TABLE events DROP FOREIGN KEY ' . $foreignKey['CONSTRAINT_NAME']);
        }

        $this->execute('ALTER TABLE events DROP COLUMN project_id');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE events ADD COLUMN project_id int(11) DEFAULT NULL');
        $this->execute('ALTER TABLE events ADD INDEX project_id (project_id)');
        $this->execute('ALTER TABLE events ADD CONSTRAINT events_project_id_fk
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
    }
}
