<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProjectPlanningModule extends AbstractMigration
{
    public function up(): void
    {
        // 1. Create generic attachments table
        $this->execute("CREATE TABLE IF NOT EXISTS attachments (
            id int(11) NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            entity_id int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            original_name varchar(255) DEFAULT NULL,
            mime_type varchar(100) NOT NULL,
            file_size int(11) DEFAULT NULL,
            file_content longblob NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_idx (entity_type, entity_id),
            KEY created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // 2. Migrate existing data
        // Song Attachments
        if ($this->hasTable('song_attachments')) {
            $this->execute("INSERT INTO attachments (entity_type, entity_id, filename, original_name, mime_type, file_size, file_content)
                SELECT 'song', song_id, filename, original_name, mime_type, file_size, file_content FROM song_attachments;");
        }

        // Finance Attachments
        if ($this->hasTable('finance_attachments')) {
            // Note: finance_attachments lacks original_name and file_size (usually)
            $this->execute("INSERT INTO attachments (entity_type, entity_id, filename, original_name, mime_type, file_content)
                SELECT 'finance', finance_id, filename, filename, mime_type, file_content FROM finance_attachments;");
        }

        // Sponsor Attachments
        if ($this->hasTable('sponsor_attachments')) {
            $this->execute("INSERT INTO attachments (entity_type, entity_id, filename, original_name, mime_type, file_content)
                SELECT 'sponsorship', sponsorship_id, filename, original_name, mime_type, file_content FROM sponsor_attachments;");
        }

        // 3. Drop old tables
        $this->execute("DROP TABLE IF EXISTS song_attachments;");
        $this->execute("DROP TABLE IF EXISTS finance_attachments;");
        $this->execute("DROP TABLE IF EXISTS sponsor_attachments;");

        // 4. Create comments table
        $this->execute("CREATE TABLE IF NOT EXISTS comments (
            id int(11) NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            entity_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            comment text NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_idx (entity_type, entity_id),
            CONSTRAINT comments_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // 5. Create tasks table
        $this->execute("CREATE TABLE IF NOT EXISTS tasks (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            assigned_to int(11) DEFAULT NULL,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            status ENUM('Offen', 'In Bearbeitung', 'Abgeschlossen', 'Blockiert') NOT NULL DEFAULT 'Offen',
            priority ENUM('Niedrig', 'Mittel', 'Hoch') NOT NULL DEFAULT 'Mittel',
            created_by int(11) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_idx (project_id),
            KEY assigned_to_idx (assigned_to),
            KEY status_idx (status),
            CONSTRAINT tasks_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT tasks_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT tasks_user_fk FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // 6. Create activities table (history)
        $this->execute("CREATE TABLE IF NOT EXISTS activities (
            id int(11) NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            entity_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            action varchar(50) NOT NULL,
            description text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_idx (entity_type, entity_id),
            CONSTRAINT activities_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // 7. Add permissions to roles
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_tasks tinyint(1) NOT NULL DEFAULT 0;");
        $this->execute("UPDATE roles SET can_manage_tasks = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung');");
    }

    public function down(): void
    {
        // 1. Remove permissions
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_tasks;");

        // 2. Drop new tables
        $this->execute("DROP TABLE IF EXISTS activities;");
        $this->execute("DROP TABLE IF EXISTS tasks;");
        $this->execute("DROP TABLE IF EXISTS comments;");

        // Cannot easily rollback data dropping without backups, we recreate empty tables
        $this->execute("DROP TABLE IF EXISTS attachments;");

        $this->execute("CREATE TABLE IF NOT EXISTS song_attachments (
            id int(11) NOT NULL AUTO_INCREMENT,
            song_id int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size int(11) NOT NULL,
            file_content longblob NOT NULL,
            PRIMARY KEY (id),
            KEY song_id (song_id),
            CONSTRAINT song_attachments_song_fk FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS finance_attachments (
            id int(11) NOT NULL AUTO_INCREMENT,
            finance_id int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_content longblob NOT NULL,
            PRIMARY KEY (id),
            KEY finance_id (finance_id),
            CONSTRAINT finance_attachments_finance_fk FOREIGN KEY (finance_id) REFERENCES finances (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS sponsor_attachments (
            id int(11) NOT NULL AUTO_INCREMENT,
            sponsorship_id int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_content longblob NOT NULL,
            uploaded_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sponsorship_id (sponsorship_id),
            CONSTRAINT sponsor_attachments_sponsorship_fk FOREIGN KEY (sponsorship_id) REFERENCES sponsorships (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }
}
