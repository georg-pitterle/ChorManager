<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ein Logo je Sponsor.
 *
 * Logos lagen bisher als gewöhnliche Anhänge am Sponsor, nicht von Mediadaten
 * oder Verträgen zu unterscheiden. Für den Druck von Plakat und Flyer muss aber
 * auf einen Blick klar sein, ob das Logo da ist - und welche Datei es ist.
 * Die Spalte zeigt auf genau einen dieser Anhänge.
 *
 * Wird der Anhang gelöscht, fällt die Verknüpfung mit (SET NULL); der Sponsor
 * hat dann wieder kein Logo.
 */
final class AddSponsorLogo extends AbstractMigration
{
    public function up(): void
    {
        // attachments.id ist INT(11) signed (aus der Erstinstallation), die
        // Fremdschlüsselspalte muss denselben Typ haben.
        $this->table('sponsors')
            ->addColumn('logo_attachment_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
                'after' => 'website',
            ])
            ->addForeignKey('logo_attachment_id', 'attachments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        // Die Logo-Dateien bleiben als gewöhnliche Anhänge am Sponsor erhalten;
        // verloren geht nur die Kennzeichnung, welcher davon das Logo ist.
        $this->table('sponsors')
            ->dropForeignKey('logo_attachment_id')
            ->removeColumn('logo_attachment_id')
            ->update();
    }
}
