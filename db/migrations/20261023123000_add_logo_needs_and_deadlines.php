<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Logo-Bedarf und Logo-Fristen.
 *
 * Fürs Plakat muss ein Sponsorenlogo viel früher da sein als für Homepage und
 * Social Media - dort reicht es am Tag vor dem Konzert. Deshalb:
 *
 * - needs_logo_print / needs_logo_online an Paket und Vereinbarung: wofür das
 *   Logo gebraucht wird. Das Paket gibt es vor, die Vereinbarung übernimmt es
 *   beim Anlegen als Kopie, wie die Gegenleistungen. Zwei Schalter statt einer
 *   Markierung je Gegenleistung, weil die Gegenleistungen bewusst ein Textfeld
 *   sind (App\Util\BenefitList).
 * - logo_deadline_print / logo_deadline_online am Projekt: feste Daten, je
 *   Projekt einmal eingetragen.
 *
 * Bestandsdaten bekommen keinen Logo-Bedarf; ob eine Vereinbarung ein Logo
 * brauchte, ließe sich nur raten.
 */
final class AddLogoNeedsAndDeadlines extends AbstractMigration
{
    public function up(): void
    {
        foreach (['sponsor_packages', 'sponsorships'] as $table) {
            $this->table($table)
                ->addColumn('needs_logo_print', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('needs_logo_online', 'boolean', ['null' => false, 'default' => false])
                ->update();
        }

        $this->table('projects')
            ->addColumn('logo_deadline_print', 'date', ['null' => true, 'default' => null, 'after' => 'end_date'])
            ->addColumn('logo_deadline_online', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'logo_deadline_print',
            ])
            ->update();
    }

    public function down(): void
    {
        // Eingetragene Fristen sind Daten, die nirgends sonst stehen - wie
        // paid_at in 20261012120000 werden sie vor dem Rückbau gezählt. Die
        // Schalter haben einen Vorgabewert und bleiben ohne Prüfung.
        $deadlines = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS rows_left FROM projects
             WHERE logo_deadline_print IS NOT NULL OR logo_deadline_online IS NOT NULL'
        )['rows_left'] ?? 0);

        if ($deadlines > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Projekt(e) tragen Logo-Fristen, die unwiederbringlich weg wären. '
                    . 'Diese zuerst bewusst selbst entfernen.',
                $deadlines
            ));
        }

        $this->table('projects')
            ->removeColumn('logo_deadline_online')
            ->removeColumn('logo_deadline_print')
            ->update();

        foreach (['sponsorships', 'sponsor_packages'] as $table) {
            $this->table($table)
                ->removeColumn('needs_logo_online')
                ->removeColumn('needs_logo_print')
                ->update();
        }
    }
}
