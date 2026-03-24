<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNewsletters extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_newsletters tinyint(1) NOT NULL DEFAULT 0;");

        $this->execute("CREATE TABLE IF NOT EXISTS newsletters (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            event_id int(11) DEFAULT NULL,
            title varchar(255) NOT NULL,
            content_html longtext NOT NULL,
            status enum('draft', 'scheduled', 'sent', 'archived') NOT NULL DEFAULT 'draft',
            recipient_count int(11) NOT NULL DEFAULT 0,
            locked_by int(11) DEFAULT NULL,
            locked_at timestamp NULL DEFAULT NULL,
            created_by int(11) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            sent_at timestamp NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY event_id (event_id),
            KEY locked_by (locked_by),
            KEY created_by (created_by),
            KEY status (status),
            CONSTRAINT newsletters_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT newsletters_ibfk_2 FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL,
            CONSTRAINT newsletters_ibfk_3 FOREIGN KEY (locked_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT newsletters_ibfk_4 FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS newsletter_templates (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            content_html longtext NOT NULL,
            project_id int(11) DEFAULT NULL,
            category varchar(100) NOT NULL DEFAULT 'general',
            created_by int(11) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY created_by (created_by),
            KEY category (category),
            CONSTRAINT newsletter_templates_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT newsletter_templates_ibfk_2 FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS newsletter_archive (
            id int(11) NOT NULL AUTO_INCREMENT,
            newsletter_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            email varchar(255) NOT NULL,
            sent_at timestamp NOT NULL DEFAULT current_timestamp(),
            read_at timestamp NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY newsletter_id (newsletter_id),
            KEY user_id (user_id),
            KEY sent_at (sent_at),
            CONSTRAINT newsletter_archive_ibfk_1 FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE,
            CONSTRAINT newsletter_archive_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS newsletter_recipients (
            id int(11) NOT NULL AUTO_INCREMENT,
            newsletter_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            status enum('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY unique_newsletter_user (newsletter_id, user_id),
            KEY user_id (user_id),
            CONSTRAINT newsletter_recipients_ibfk_1 FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE,
            CONSTRAINT newsletter_recipients_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("UPDATE roles SET can_manage_newsletters = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung');");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS newsletter_recipients;');
        $this->execute('DROP TABLE IF EXISTS newsletter_archive;');
        $this->execute('DROP TABLE IF EXISTS newsletter_templates;');
        $this->execute('DROP TABLE IF EXISTS newsletters;');
        $this->execute('ALTER TABLE roles DROP COLUMN can_manage_newsletters;');
    }
}
