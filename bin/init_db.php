<?php
declare(strict_types=1)
;

$dbPath = __DIR__ . '/../database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Enable foreign keys
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // 1. roles
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            hierarchy_level INTEGER NOT NULL,
            can_manage_users INTEGER NOT NULL DEFAULT 0,
            can_edit_users INTEGER NOT NULL DEFAULT 0
        );
    ");

    // 2. voice_groups
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voice_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        );
    ");

    // 3. sub_voices
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sub_voices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            voice_group_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            FOREIGN KEY (voice_group_id) REFERENCES voice_groups(id) ON DELETE CASCADE
        );
    ");

    // 4. users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        );
    ");

    // 4c. Migration: last_project_id für Benutzer (zuletzt gewähltes Projekt)
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_project_id INTEGER");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') === false) {
            throw $e;
        }
    }

    // 4a. user_roles (M:N)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_roles (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, role_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        );
    ");

    // 4b. user_voice_groups (M:N)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_voice_groups (
            user_id INTEGER NOT NULL,
            voice_group_id INTEGER NOT NULL,
            sub_voice_id INTEGER,
            PRIMARY KEY (user_id, voice_group_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (voice_group_id) REFERENCES voice_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (sub_voice_id) REFERENCES sub_voices(id) ON DELETE SET NULL
        );
    ");

    // 5. projects
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            start_date TEXT,
            end_date TEXT
        );
    ");

    // 6. events
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            event_date TEXT NOT NULL,
            type TEXT NOT NULL,
            project_id INTEGER,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
        );
    ");

    // 7. attendances
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL, -- 'present', 'excused', 'unexcused'
            note TEXT,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(event_id, user_id)
        );
    ");

    // 8. project_users (Benutzer-Projekt-Zuordnung)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_users (
            project_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            PRIMARY KEY (project_id, user_id),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    // 9. app_settings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            binary_content BLOB,
            mime_type TEXT
        );
    ");

    // Insert Default App Settings if not exist
    $stmt = $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key = 'app_name'");
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute(['app_name', 'Chorkuma App']);
    }

    // Migration: Add can_manage_project_members to roles (falls Tabelle bereits existiert)
    try {
        $pdo->exec("ALTER TABLE roles ADD COLUMN can_manage_project_members INTEGER NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') === false) {
            throw $e;
        }
    }

    // Migration: Add can_manage_master_data to roles
    try {
        $pdo->exec("ALTER TABLE roles ADD COLUMN can_manage_master_data INTEGER NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') === false) {
            throw $e;
        }
    }

    // Rollen Admin, Vorstand, Chorleitung, Stimmvertretung erhalten das Recht (immer ausführen)
    $pdo->exec("UPDATE roles SET can_manage_project_members = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung', 'Stimmvertretung')");
    $pdo->exec("UPDATE roles SET can_manage_master_data = 1 WHERE name IN ('Admin', 'Vorstand', 'Chorleitung')");

    // Insert Default Data
    $stmt = $pdo->query("SELECT COUNT(*) FROM roles");
    if ($stmt->fetchColumn() == 0) {
        // Insert default roles
        $pdo->exec("
            INSERT INTO roles (name, hierarchy_level, can_manage_users, can_edit_users, can_manage_project_members, can_manage_master_data) VALUES 
            ('Admin', 100, 1, 1, 1, 1),
            ('Vorstand', 80, 1, 1, 1, 1),
            ('Chorleitung', 80, 1, 0, 1, 1),
            ('Stimmvertretung', 50, 0, 0, 1, 0),
            ('Ersatzvertretung', 40, 0, 0, 0, 0),
            ('Mitglied', 0, 0, 0, 0, 0);
            
            INSERT INTO voice_groups (name) VALUES 
            ('Sopran'), ('Alt'), ('Tenor'), ('Bass');
            
            INSERT INTO sub_voices (voice_group_id, name) VALUES 
            (1, 'Sopran 1'), (1, 'Sopran 2'),
            (2, 'Alt 1'), (2, 'Alt 2'),
            (3, 'Tenor 1'), (3, 'Tenor 2'),
            (4, 'Bass 1'), (4, 'Bass 2');
        ");

        $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)");
        $stmt->execute(['Admin', 'User', 'admin@example.com', $passwordHash]);

        $adminId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $stmt->execute([$adminId, 1]); // role_id 1 is Admin
    }

    echo "Database initialization completed successfully.\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
