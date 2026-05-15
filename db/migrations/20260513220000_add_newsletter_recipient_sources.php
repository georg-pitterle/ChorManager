<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddNewsletterRecipientSources extends AbstractMigration
{
    public function up(): void
    {
        $this->table('newsletter_recipient_sources')
            ->addColumn('newsletter_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'event_attendees', 'role', 'user']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['newsletter_id'])
            ->addForeignKey(
                'newsletter_id',
                'newsletters',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();

        $this->execute(
            "INSERT INTO newsletter_recipient_sources (newsletter_id, source_type, reference_id)
             SELECT id, 'project_members', project_id
             FROM newsletters"
        );

        $this->execute(
            "INSERT INTO newsletter_recipient_sources (newsletter_id, source_type, reference_id)
             SELECT id, 'event_attendees', event_id
             FROM newsletters
             WHERE event_id IS NOT NULL"
        );

        $this->table('newsletters')
            ->dropForeignKey('event_id')
            ->removeIndex(['event_id'])
            ->removeColumn('event_id')
            ->update();
    }

    public function down(): void
    {
        $this->table('newsletters')
            ->addColumn('event_id', 'integer', ['null' => true, 'after' => 'project_id'])
            ->addIndex(['event_id'])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        $this->execute(
            "UPDATE newsletters n
               INNER JOIN newsletter_recipient_sources s
               ON s.newsletter_id = n.id
              AND s.source_type = 'event_attendees'
             SET n.event_id = s.reference_id"
        );

        $this->table('newsletter_recipient_sources')->drop()->save();
    }
}
