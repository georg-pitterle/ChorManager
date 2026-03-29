<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropReadAtFromNewsletterArchive extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('newsletter_archive');
        $table->removeColumn('read_at')->save();
    }

    public function down(): void
    {
        $table = $this->table('newsletter_archive');
        $table->addColumn('read_at', 'timestamp', ['null' => true, 'default' => null])->save();
    }
}
