<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Je Newsletter-Datei steht hier, wie sie beim Empfänger ankommt: als echter
 * Mail-Anhang (`attach`) oder als Download-Link im Newsletter (`link`).
 *
 * Der Standard ist `link`, weil das die harmlose Richtung ist. Eine Zeile ohne
 * bewusst gesetzten Modus bläht keine Mail auf und läuft in kein Größenlimit
 * eines Empfänger-Postfachs. Anhänge der übrigen Bereiche - Finanzen, Aufgaben,
 * Sponsoring, Repertoire - tragen die Spalte mit und werten sie nie aus; eine
 * eigene Tabelle nur für dieses eine Feld wäre ein zweiter Lebenszyklus für
 * Daten, die ohnehin an der Anhang-Zeile hängen.
 */
final class AddDeliveryModeToAttachments extends AbstractMigration
{
    public function up(): void
    {
        $this->table('attachments')
            ->addColumn('delivery_mode', 'enum', [
                'values' => ['attach', 'link'],
                'null' => false,
                'default' => 'link',
                'after' => 'file_size',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('attachments')
            ->removeColumn('delivery_mode')
            ->update();
    }
}
