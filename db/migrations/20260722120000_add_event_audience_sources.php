<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEventAudienceSources extends AbstractMigration
{
    public function up(): void
    {
        $this->table('event_audience_sources')
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'role', 'user', 'voice_group']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['event_id'])
            ->addForeignKey(
                'event_id',
                'events',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();

        $this->execute(
            "INSERT INTO event_audience_sources (event_id, source_type, reference_id)
             SELECT id, 'project_members', project_id
             FROM events
             WHERE project_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->table('event_audience_sources')->drop()->save();
    }
}
