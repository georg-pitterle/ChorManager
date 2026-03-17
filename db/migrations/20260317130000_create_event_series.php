<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEventSeries extends AbstractMigration
{
    public function up(): void
    {
        // 1. Create event_series table
        $this->execute("CREATE TABLE IF NOT EXISTS event_series (
            id int(11) NOT NULL AUTO_INCREMENT,
            frequency varchar(20) NOT NULL,
            recurrence_interval int(11) NOT NULL DEFAULT 1,
            weekdays varchar(255) DEFAULT NULL,
            end_date date NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        // 2. Add series_id to events table
        $this->execute("ALTER TABLE events 
            ADD COLUMN series_id int(11) DEFAULT NULL AFTER id,
            ADD CONSTRAINT fk_events_series FOREIGN KEY (series_id) REFERENCES event_series(id) ON DELETE SET NULL ON UPDATE CASCADE;");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE events DROP FOREIGN KEY fk_events_series;");
        $this->execute("ALTER TABLE events DROP COLUMN series_id;");
        $this->execute("DROP TABLE IF EXISTS event_series;");
    }
}
