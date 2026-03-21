<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSponsoring extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS sponsor_packages (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            description text DEFAULT NULL,
            min_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            color varchar(50) NOT NULL DEFAULT 'info',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS sponsors (
            id int(11) NOT NULL AUTO_INCREMENT,
            type ENUM('organization','person') NOT NULL DEFAULT 'organization',
            name varchar(255) NOT NULL,
            contact_person varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(100) DEFAULT NULL,
            address text DEFAULT NULL,
            website varchar(255) DEFAULT NULL,
            notes text DEFAULT NULL,
            status ENUM('prospect','contacted','negotiating','active','paused','closed') NOT NULL DEFAULT 'prospect',
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS sponsorships (
            id int(11) NOT NULL AUTO_INCREMENT,
            sponsor_id int(11) NOT NULL,
            project_id int(11) DEFAULT NULL,
            package_id int(11) DEFAULT NULL,
            assigned_user_id int(11) DEFAULT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('prospect','contacted','negotiating','active','paused','closed') NOT NULL DEFAULT 'prospect',
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sponsor_id (sponsor_id),
            KEY project_id (project_id),
            KEY package_id (package_id),
            KEY assigned_user_id (assigned_user_id),
            CONSTRAINT sponsorships_sponsor_fk FOREIGN KEY (sponsor_id) REFERENCES sponsors (id) ON DELETE CASCADE,
            CONSTRAINT sponsorships_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
            CONSTRAINT sponsorships_package_fk FOREIGN KEY (package_id) REFERENCES sponsor_packages (id) ON DELETE SET NULL,
            CONSTRAINT sponsorships_user_fk FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $this->execute("CREATE TABLE IF NOT EXISTS sponsoring_contacts (
            id int(11) NOT NULL AUTO_INCREMENT,
            sponsor_id int(11) NOT NULL,
            sponsorship_id int(11) DEFAULT NULL,
            user_id int(11) DEFAULT NULL,
            contact_date date NOT NULL,
            type ENUM('call','email','meeting','letter','other') NOT NULL DEFAULT 'other',
            summary text NOT NULL,
            follow_up_date date DEFAULT NULL,
            follow_up_done tinyint(1) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sponsor_id (sponsor_id),
            KEY sponsorship_id (sponsorship_id),
            KEY user_id (user_id),
            CONSTRAINT sponsoring_contacts_sponsor_fk FOREIGN KEY (sponsor_id) REFERENCES sponsors (id) ON DELETE CASCADE,
            CONSTRAINT sponsoring_contacts_sponsorship_fk FOREIGN KEY (sponsorship_id) REFERENCES sponsorships (id) ON DELETE SET NULL,
            CONSTRAINT sponsoring_contacts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
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

        $this->execute("ALTER TABLE roles ADD COLUMN can_manage_sponsoring tinyint(1) NOT NULL DEFAULT 0;");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE roles DROP COLUMN can_manage_sponsoring;");
        $this->execute("DROP TABLE IF EXISTS sponsor_attachments;");
        $this->execute("DROP TABLE IF EXISTS sponsoring_contacts;");
        $this->execute("DROP TABLE IF EXISTS sponsorships;");
        $this->execute("DROP TABLE IF EXISTS sponsors;");
        $this->execute("DROP TABLE IF EXISTS sponsor_packages;");
    }
}
