<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Initial extends AbstractMigration
{
    public function up(): void
    {
        // users (no FKs)
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

        // roles (no FKs)
        $this->execute("CREATE TABLE IF NOT EXISTS roles (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            hierarchy_level int(11) NOT NULL DEFAULT 0,
            can_manage_users tinyint(1) NOT NULL DEFAULT 0,
            can_edit_users tinyint(1) NOT NULL DEFAULT 0,
            can_manage_project_members tinyint(1) NOT NULL DEFAULT 0,
            can_manage_finances tinyint(1) NOT NULL DEFAULT 0,
            can_manage_master_data tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // projects (no FKs)
        $this->execute("CREATE TABLE IF NOT EXISTS projects (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // voice_groups (no FKs)
        $this->execute("CREATE TABLE IF NOT EXISTS voice_groups (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // sub_voices (references voice_groups)
        $this->execute("CREATE TABLE IF NOT EXISTS sub_voices (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            voice_group_id int(11) NOT NULL,
            PRIMARY KEY (id),
            KEY voice_group_id (voice_group_id),
            CONSTRAINT sub_voices_ibfk_1 FOREIGN KEY (voice_group_id) REFERENCES voice_groups (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // settings (no FKs)
        $this->execute("CREATE TABLE IF NOT EXISTS settings (
            setting_key varchar(50) NOT NULL,
            setting_value varchar(255) NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // app_settings (no FKs)
        $this->execute("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key varchar(50) NOT NULL,
            setting_value varchar(255) NOT NULL,
            binary_content longblob NOT NULL,
            mime_type varchar(100) NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // finances (no FKs)
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

        // events (references projects)
        $this->execute("CREATE TABLE IF NOT EXISTS events (
            id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            project_id int(11) NOT NULL,
            event_date datetime NOT NULL,
            type varchar(255) NOT NULL,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            CONSTRAINT events_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // attendance (references events, users)
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

        // finance_attachments (references finances)
        $this->execute("CREATE TABLE IF NOT EXISTS finance_attachments (
            id int(11) NOT NULL AUTO_INCREMENT,
            finance_id int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_content longblob NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY finance_id (finance_id),
            CONSTRAINT finance_attachments_ibfk_1 FOREIGN KEY (finance_id) REFERENCES finances (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // project_users (references projects, users)
        $this->execute("CREATE TABLE IF NOT EXISTS project_users (
            project_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            PRIMARY KEY (project_id, user_id),
            KEY user_id (user_id),
            CONSTRAINT project_users_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT project_users_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // user_voice_groups (references users, voice_groups, sub_voices)
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

        // user_roles (references users, roles)
        $this->execute("CREATE TABLE IF NOT EXISTS user_roles (
            user_id int(11) NOT NULL,
            role_id int(11) NOT NULL,
            PRIMARY KEY (user_id, role_id),
            KEY role_id (role_id),
            CONSTRAINT user_roles_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT user_roles_ibfk_2 FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // seed data from db.sql
        $this->execute("INSERT INTO roles (id, name, hierarchy_level, can_manage_users, can_edit_users, can_manage_project_members, can_manage_finances, can_manage_master_data) VALUES
            (1,'Admin',100,1,1,1,0,1),
            (2,'Vorstand',80,1,1,1,0,1),
            (3,'Chorleitung',80,1,0,1,0,1),
            (4,'Stimmvertretung',50,0,0,1,0,0),
            (5,'Ersatzvertretung',40,0,0,0,0,0),
            (6,'Mitglied',0,0,0,0,0,0);");

        $this->execute("INSERT INTO voice_groups (id, name) VALUES
            (1,'Sopran'),
            (2,'Alt'),
            (3,'Tenor'),
            (4,'Bass');");

        $this->execute("INSERT INTO sub_voices (id, name, voice_group_id) VALUES
            (1,'Sopran 1',1),
            (2,'Sopran 2',1),
            (3,'Alt 1',2),
            (4,'Alt 2',2),
            (5,'Tenor 1',3),
            (6,'Tenor 2',3),
            (7,'Bass 1',4),
            (8,'Bass 2',4);");

        $this->execute("INSERT INTO settings (setting_key, setting_value) VALUES
            ('app_name','Chor-Manager'),
            ('fiscal_year_start','01.10.');");
    }

    public function down(): void
    {
        // drop tables in reverse order of creation to satisfy foreign keys
        $this->execute('DROP TABLE IF EXISTS user_roles;');
        $this->execute('DROP TABLE IF EXISTS user_voice_groups;');
        $this->execute('DROP TABLE IF EXISTS project_users;');
        $this->execute('DROP TABLE IF EXISTS finance_attachments;');
        $this->execute('DROP TABLE IF EXISTS attendance;');
        $this->execute('DROP TABLE IF EXISTS events;');
        $this->execute('DROP TABLE IF EXISTS finances;');
        $this->execute('DROP TABLE IF EXISTS app_settings;');
        $this->execute('DROP TABLE IF EXISTS settings;');
        $this->execute('DROP TABLE IF EXISTS sub_voices;');
        $this->execute('DROP TABLE IF EXISTS voice_groups;');
        $this->execute('DROP TABLE IF EXISTS projects;');
        $this->execute('DROP TABLE IF EXISTS roles;');
        $this->execute('DROP TABLE IF EXISTS users;');
    }
}
