<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStartsAtEndsAtToEvents extends AbstractMigration
{
    public function up(): void
    {
        // 1. Add the two new columns as nullable for the backfill phase.
        $this->execute('ALTER TABLE events ADD COLUMN starts_at DATETIME NULL AFTER event_date');
        $this->execute('ALTER TABLE events ADD COLUMN ends_at DATETIME NULL AFTER starts_at');

        // 2. Backfill: keep the original calendar date, default window is 19:00–21:00.
        $this->execute(
            "UPDATE events SET starts_at = CONCAT(DATE(event_date), ' 19:00:00'), "
                . "ends_at = CONCAT(DATE(event_date), ' 21:00:00')"
        );

        // 3. Make columns NOT NULL now that every row has a value.
        $this->execute('ALTER TABLE events MODIFY COLUMN starts_at DATETIME NOT NULL');
        $this->execute('ALTER TABLE events MODIFY COLUMN ends_at DATETIME NOT NULL');

        // 4. Remove the old column.
        $this->execute('ALTER TABLE events DROP COLUMN event_date');

        // 5. Index starts_at for ORDER BY and bare range predicates (WHERE starts_at >= ?).
        //    Note: WHERE DATE(starts_at) >= ? is not index-backed; use bare range predicates instead.
        $this->execute('ALTER TABLE events ADD INDEX idx_events_starts_at (starts_at)');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE events DROP INDEX idx_events_starts_at');
        $this->execute('ALTER TABLE events ADD COLUMN event_date DATETIME NULL AFTER ends_at');
        $this->execute('UPDATE events SET event_date = starts_at');
        $this->execute('ALTER TABLE events MODIFY COLUMN event_date DATETIME NOT NULL');
        $this->execute('ALTER TABLE events DROP COLUMN ends_at');
        $this->execute('ALTER TABLE events DROP COLUMN starts_at');
    }
}
