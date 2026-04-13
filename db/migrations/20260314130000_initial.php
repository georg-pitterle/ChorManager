<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Initial extends AbstractMigration
{
    public function up(): void
    {
        // users
        $this->execute("CREATE TABLE IF NOT EXISTS users (
            id int(11) NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            password varchar(255) NOT NULL,
            first_name varchar(255) NOT NULL,
            last_name varchar(255) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            last_project_id int(11) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // roles (all permission columns in final state)
        $this->execute("CREATE TABLE IF NOT EXISTS roles (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            hierarchy_level int(11) NOT NULL DEFAULT 0,
            can_manage_users tinyint(1) NOT NULL DEFAULT 0,
            can_edit_users tinyint(1) NOT NULL DEFAULT 0,
            can_manage_project_members tinyint(1) NOT NULL DEFAULT 0,
            can_manage_finances tinyint(1) NOT NULL DEFAULT 0,
            can_manage_master_data tinyint(1) NOT NULL DEFAULT 0,
            can_manage_sponsoring tinyint(1) NOT NULL DEFAULT 0,
            can_manage_song_library tinyint(1) NOT NULL DEFAULT 0,
            can_manage_newsletters tinyint(1) NOT NULL DEFAULT 0,
            can_manage_tasks tinyint(1) NOT NULL DEFAULT 0,
            can_manage_attendance tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // projects
        $this->execute("CREATE TABLE IF NOT EXISTS projects (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // voice_groups
        $this->execute("CREATE TABLE IF NOT EXISTS voice_groups (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // sub_voices
        $this->execute("CREATE TABLE IF NOT EXISTS sub_voices (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            voice_group_id int(11) NOT NULL,
            PRIMARY KEY (id),
            KEY voice_group_id (voice_group_id),
            CONSTRAINT sub_voices_ibfk_1 FOREIGN KEY (voice_group_id) REFERENCES voice_groups (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // settings
        $this->execute("CREATE TABLE IF NOT EXISTS settings (
            setting_key varchar(50) NOT NULL,
            setting_value varchar(255) NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // app_settings
        $this->execute("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key varchar(50) NOT NULL,
            setting_value varchar(255) NOT NULL,
            binary_content longblob NOT NULL,
            mime_type varchar(100) NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // finances
        $this->execute("CREATE TABLE IF NOT EXISTS finances (
            id int(11) NOT NULL AUTO_INCREMENT,
            running_number int(11) NOT NULL,
            invoice_date date NOT NULL,
            payment_date date DEFAULT NULL,
            description varchar(255) NOT NULL,
            group_name varchar(100) DEFAULT NULL,
            type enum('income','expense') NOT NULL,
            amount decimal(10,2) NOT NULL,
            payment_method enum('cash','bank_transfer') NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY running_number (running_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // event_series (referenced by events)
        $this->execute("CREATE TABLE IF NOT EXISTS event_series (
            id int(11) NOT NULL AUTO_INCREMENT,
            frequency varchar(20) NOT NULL,
            recurrence_interval int(11) NOT NULL DEFAULT 1,
            weekdays varchar(255) DEFAULT NULL,
            end_date date NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // event_types (referenced by events)
        $this->execute("CREATE TABLE IF NOT EXISTS event_types (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            color varchar(50) NOT NULL DEFAULT 'info',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // events (final state: series_id, location, nullable project_id, event_type_id, legacy type column)
        $this->execute("CREATE TABLE IF NOT EXISTS events (
            id int(11) NOT NULL AUTO_INCREMENT,
            series_id int(11) DEFAULT NULL,
            title varchar(255) NOT NULL,
            location varchar(255) DEFAULT NULL,
            project_id int(11) DEFAULT NULL,
            event_type_id int(11) DEFAULT NULL,
            event_date datetime NOT NULL,
            type varchar(255) NOT NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY series_id (series_id),
            KEY event_type_id (event_type_id),
            CONSTRAINT events_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_events_series FOREIGN KEY (series_id) REFERENCES event_series (id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_events_event_type FOREIGN KEY (event_type_id) REFERENCES event_types (id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // attendance
        $this->execute("CREATE TABLE IF NOT EXISTS attendance (
            id int(11) NOT NULL AUTO_INCREMENT,
            event_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            status varchar(50) NOT NULL,
            note varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id, user_id),
            KEY user_id (user_id),
            CONSTRAINT attendance_ibfk_1 FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
            CONSTRAINT attendance_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // project_users
        $this->execute("CREATE TABLE IF NOT EXISTS project_users (
            project_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            PRIMARY KEY (project_id, user_id),
            KEY user_id (user_id),
            CONSTRAINT project_users_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT project_users_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // user_voice_groups
        $this->execute("CREATE TABLE IF NOT EXISTS user_voice_groups (
            user_id int(11) NOT NULL,
            voice_group_id int(11) NOT NULL,
            sub_voice_id int(11) DEFAULT NULL,
            PRIMARY KEY (user_id, voice_group_id),
            KEY voice_group_id (voice_group_id),
            KEY sub_voice_id (sub_voice_id),
            CONSTRAINT user_voice_groups_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT user_voice_groups_ibfk_2 FOREIGN KEY (voice_group_id) REFERENCES voice_groups (id) ON DELETE CASCADE,
            CONSTRAINT user_voice_groups_ibfk_3 FOREIGN KEY (sub_voice_id) REFERENCES sub_voices (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // user_roles
        $this->execute("CREATE TABLE IF NOT EXISTS user_roles (
            user_id int(11) NOT NULL,
            role_id int(11) NOT NULL,
            PRIMARY KEY (user_id, role_id),
            KEY role_id (role_id),
            CONSTRAINT user_roles_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT user_roles_ibfk_2 FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // password_resets
        $this->execute("CREATE TABLE IF NOT EXISTS password_resets (
            id int(11) NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            token varchar(255) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // remember_logins
        $this->execute("CREATE TABLE IF NOT EXISTS remember_logins (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            selector varchar(18) NOT NULL,
            token_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at datetime DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY selector (selector),
            KEY user_id (user_id),
            CONSTRAINT remember_logins_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // sponsor_packages
        $this->execute("CREATE TABLE IF NOT EXISTS sponsor_packages (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            description text DEFAULT NULL,
            min_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            color varchar(50) NOT NULL DEFAULT 'info',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // sponsors
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

        // sponsorships
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

        // sponsoring_contacts
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

        // songs
        $this->execute("CREATE TABLE IF NOT EXISTS songs (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            title varchar(255) NOT NULL,
            composer varchar(255) DEFAULT NULL,
            arranger varchar(255) DEFAULT NULL,
            publisher varchar(255) DEFAULT NULL,
            created_by_user_id int(11) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY created_by_user_id (created_by_user_id),
            CONSTRAINT songs_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT songs_ibfk_2 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // newsletters (status ENUM in final state: draft, sent)
        $this->execute("CREATE TABLE IF NOT EXISTS newsletters (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            event_id int(11) DEFAULT NULL,
            title varchar(255) NOT NULL,
            content_html longtext NOT NULL,
            status enum('draft','sent') NOT NULL DEFAULT 'draft',
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

        // newsletter_templates
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

        // newsletter_archive (read_at column removed in final state)
        $this->execute("CREATE TABLE IF NOT EXISTS newsletter_archive (
            id int(11) NOT NULL AUTO_INCREMENT,
            newsletter_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            email varchar(255) NOT NULL,
            sent_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY newsletter_id (newsletter_id),
            KEY user_id (user_id),
            KEY sent_at (sent_at),
            CONSTRAINT newsletter_archive_ibfk_1 FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE,
            CONSTRAINT newsletter_archive_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // newsletter_recipients
        $this->execute("CREATE TABLE IF NOT EXISTS newsletter_recipients (
            id int(11) NOT NULL AUTO_INCREMENT,
            newsletter_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            status enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY unique_newsletter_user (newsletter_id, user_id),
            KEY user_id (user_id),
            CONSTRAINT newsletter_recipients_ibfk_1 FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE,
            CONSTRAINT newsletter_recipients_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // attachments (generic; replaces finance_attachments, song_attachments, sponsor_attachments)
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

        // comments
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

        // tasks
        $this->execute("CREATE TABLE IF NOT EXISTS tasks (
            id int(11) NOT NULL AUTO_INCREMENT,
            project_id int(11) NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            assigned_to int(11) DEFAULT NULL,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            status ENUM('Offen','In Bearbeitung','Abgeschlossen','Blockiert') NOT NULL DEFAULT 'Offen',
            priority ENUM('Niedrig','Mittel','Hoch') NOT NULL DEFAULT 'Mittel',
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

        // activities
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

        // ── Seed data ──────────────────────────────────────────────────────────

        // roles with all permissions in their final correct state
        $this->execute("INSERT INTO roles
            (id, name, hierarchy_level,
             can_manage_users, can_edit_users, can_manage_project_members,
             can_manage_finances, can_manage_master_data,
             can_manage_sponsoring, can_manage_song_library,
             can_manage_newsletters, can_manage_tasks, can_manage_attendance)
            VALUES
            (1,'Admin',          100, 1,1,1, 1,1, 1,1,1,1,1),
            (2,'Vorstand',        80, 1,1,1, 1,1, 1,1,1,1,1),
            (3,'Chorleitung',     80, 1,0,1, 0,1, 1,1,1,1,1),
            (4,'Stimmvertretung', 50, 0,0,1, 0,0, 0,0,0,0,1),
            (5,'Ersatzvertretung',40, 0,0,0, 0,0, 0,0,0,0,1),
            (6,'Mitglied',         0, 0,0,0, 0,0, 0,0,0,0,0);");

        $this->execute("INSERT INTO voice_groups (id, name) VALUES
            (1,'Sopran'),(2,'Alt'),(3,'Tenor'),(4,'Bass');");

        $this->execute("INSERT INTO sub_voices (id, name, voice_group_id) VALUES
            (1,'Sopran 1',1),(2,'Sopran 2',1),
            (3,'Alt 1',2),(4,'Alt 2',2),
            (5,'Tenor 1',3),(6,'Tenor 2',3),
            (7,'Bass 1',4),(8,'Bass 2',4);");

        $this->execute("INSERT INTO settings (setting_key, setting_value) VALUES
            ('app_name','Chor-Manager'),
            ('fiscal_year_start','01.10.');");

        $this->execute("INSERT INTO event_types (name, color) VALUES
            ('Probe','info'),('Auftritt','danger'),('Sondertermin','warning');");
    }

    public function down(): void
    {
        $this->execute('SET FOREIGN_KEY_CHECKS = 0;');
        $this->execute('DROP TABLE IF EXISTS activities;');
        $this->execute('DROP TABLE IF EXISTS tasks;');
        $this->execute('DROP TABLE IF EXISTS comments;');
        $this->execute('DROP TABLE IF EXISTS attachments;');
        $this->execute('DROP TABLE IF EXISTS newsletter_recipients;');
        $this->execute('DROP TABLE IF EXISTS newsletter_archive;');
        $this->execute('DROP TABLE IF EXISTS newsletter_templates;');
        $this->execute('DROP TABLE IF EXISTS newsletters;');
        $this->execute('DROP TABLE IF EXISTS songs;');
        $this->execute('DROP TABLE IF EXISTS sponsoring_contacts;');
        $this->execute('DROP TABLE IF EXISTS sponsorships;');
        $this->execute('DROP TABLE IF EXISTS sponsors;');
        $this->execute('DROP TABLE IF EXISTS sponsor_packages;');
        $this->execute('DROP TABLE IF EXISTS remember_logins;');
        $this->execute('DROP TABLE IF EXISTS password_resets;');
        $this->execute('DROP TABLE IF EXISTS user_roles;');
        $this->execute('DROP TABLE IF EXISTS user_voice_groups;');
        $this->execute('DROP TABLE IF EXISTS project_users;');
        $this->execute('DROP TABLE IF EXISTS attendance;');
        $this->execute('DROP TABLE IF EXISTS events;');
        $this->execute('DROP TABLE IF EXISTS event_types;');
        $this->execute('DROP TABLE IF EXISTS event_series;');
        $this->execute('DROP TABLE IF EXISTS finances;');
        $this->execute('DROP TABLE IF EXISTS app_settings;');
        $this->execute('DROP TABLE IF EXISTS settings;');
        $this->execute('DROP TABLE IF EXISTS sub_voices;');
        $this->execute('DROP TABLE IF EXISTS voice_groups;');
        $this->execute('DROP TABLE IF EXISTS projects;');
        $this->execute('DROP TABLE IF EXISTS roles;');
        $this->execute('DROP TABLE IF EXISTS users;');
        $this->execute('SET FOREIGN_KEY_CHECKS = 1;');
    }
}
