<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MakeProjectIdNullableInEvents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE events MODIFY project_id int(11) DEFAULT NULL;");
    }

    public function down(): void
    {
        // Note: This might fail if there are already null values, but it's for rollback.
        $this->execute("ALTER TABLE events MODIFY project_id int(11) NOT NULL;");
    }
}
