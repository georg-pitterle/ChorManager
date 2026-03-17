<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEventTypes extends AbstractMigration
{
    public function up(): void
    {
        // 1. Create event_types table
        $this->execute("CREATE TABLE IF NOT EXISTS event_types (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            color varchar(50) NOT NULL DEFAULT 'info',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // 2. Seed initial event types
        $this->execute("INSERT INTO event_types (name, color) VALUES 
            ('Probe', 'info'), 
            ('Auftritt', 'danger'), 
            ('Sondertermin', 'warning');");

        // 3. Add event_type_id to events table
        $this->table('events')
            ->addColumn('event_type_id', 'integer', ['null' => true, 'after' => 'project_id'])
            ->addForeignKey('event_type_id', 'event_types', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->update();

        // 4. Migrate existing data
        $this->execute("UPDATE events e 
            JOIN event_types et ON e.type = et.name 
            SET e.event_type_id = et.id");

        // 5. Remove old type column (optional but cleaner)
        // $this->table('events')->removeColumn('type')->update();
    }

    public function down(): void
    {
        $this->table('events')
            ->dropForeignKey('event_type_id')
            ->removeColumn('event_type_id')
            ->update();

        $this->execute("DROP TABLE IF EXISTS event_types;");
    }
}
