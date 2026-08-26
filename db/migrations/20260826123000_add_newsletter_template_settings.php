<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Vorlagen halten bisher nur den Inhalt fest. Damit eine Vorlage einen
 * vollständigen Newsletter vorbereiten kann, kommen die restlichen
 * Newsletter-Einstellungen dazu: ein vorgeschlagener Titel und die
 * Empfängerquellen. Der Kontext (project_id) steht bereits in der Tabelle.
 */
final class AddNewsletterTemplateSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('newsletter_templates')
            ->addColumn('default_title', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'name',
            ])
            ->update();

        $this->table('newsletter_template_recipient_sources')
            ->addColumn('template_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', [
                'values' => ['project_members', 'event_attendees', 'role', 'user'],
                'null' => false,
            ])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['template_id'])
            ->addIndex(['template_id', 'source_type', 'reference_id'], [
                'unique' => true,
                'name' => 'uq_newsletter_template_recipient_source',
            ])
            ->addForeignKey(
                'template_id',
                'newsletter_templates',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();
    }

    public function down(): void
    {
        // Beide Rücknahmen verwerfen Daten, die es vor dieser Migration nicht
        // gab: Titelvorschlag und Vorlagen-Empfängerquellen stehen nirgendwo
        // sonst. Es gibt daher nichts zurückzuschreiben.
        $this->table('newsletter_template_recipient_sources')->drop()->save();

        $this->table('newsletter_templates')
            ->removeColumn('default_title')
            ->update();
    }
}
