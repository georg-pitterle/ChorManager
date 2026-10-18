<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die gemeinsame Tabellenleiste auf eine Arbeitszeile.
 *
 * Kritik der Mitgliederliste vom Oktober 2026: Die Leiste war an beiden Breiten
 * 173 px hoch, auf drei bis vier Zeilen, mit neun Bedienelementen ohne
 * Ordnung - "Sortierung" und "Zurücksetzen" allein in Zeile zwei, "Ansicht"
 * allein in Zeile drei. Jetzt steht vorn, was die Liste eingrenzt (Suche,
 * Filter), und alles, was nur die Darstellung ändert, in einem Menü.
 */
final class TableToolbarCompactFeatureTest extends TestCase
{
    private function toolbar(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/partials/table_toolbar.twig');
        $this->assertIsString($source);

        return $source;
    }

    private function viewMenu(): string
    {
        $toolbar = $this->toolbar();
        $start = strpos($toolbar, 'data-table-view-toggle');
        $this->assertNotFalse($start);
        $end = strpos($toolbar, '{# /Ansicht #}', $start);
        $this->assertNotFalse($end, 'Ende des Ansicht-Menüs fehlt.');

        return substr($toolbar, $start, $end - $start);
    }

    public function testDisplayOnlyControlsLiveInTheViewMenu(): void
    {
        $menu = $this->viewMenu();

        foreach (
            [
                'data-table-sort-panel',
                'data-table-sort-current',
                'data-table-sort-clear',
                'data-table-page-size',
                'data-table-reset',
                'data-table-mode="auto"',
            ] as $marker
        ) {
            $this->assertStringContainsString($marker, $menu, $marker . ' gehört ins Menü „Ansicht“.');
        }
    }

    public function testGroupingSitsVisiblyNextToTheViewMenu(): void
    {
        $toolbar = $this->toolbar();
        $main = substr($toolbar, (int) strpos($toolbar, 'table-toolbar__main'));
        $main = substr($main, 0, (int) strpos($main, 'data-table-plugin-slot'));

        // Gruppieren ist für einen Chor die wichtigste Ansicht; im Menü lag sie
        // eine Ebene zu tief. Sie steht jetzt sichtbar daneben, nicht im Menü.
        $this->assertStringContainsString('data-table-view-slot', $main);
        $this->assertStringNotContainsString('data-table-view-slot', $this->viewMenu());
    }

    public function testThereIsNoSeparateSortButtonAnymore(): void
    {
        $this->assertStringNotContainsString('data-table-sort-toggle', $this->toolbar());
        $this->assertStringNotContainsString('>Sortierung</button>', $this->toolbar());
    }

    public function testSearchFilterMenuAndCountShareTheFirstRow(): void
    {
        $toolbar = $this->toolbar();
        $main = substr($toolbar, (int) strpos($toolbar, 'table-toolbar__main'));
        $main = substr($main, 0, (int) strpos($main, 'data-table-plugin-slot'));

        $this->assertStringContainsString('data-table-search', $main);
        $this->assertStringContainsString('data-table-filter-toggle', $main);
        $this->assertStringContainsString('data-table-view-toggle', $main);
        $this->assertStringContainsString('data-table-result-count', $main);
    }

    public function testPluginsReceiveTheViewSlot(): void
    {
        $engine = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/table-engine.js');
        $group = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/table-plugins/users-group-plugin.js');

        $this->assertStringContainsString("viewSlot: container.querySelector('[data-table-view-slot]')", $engine);
        // Gruppieren ändert die Darstellung, nicht die Treffermenge - also ins
        // Ansicht-Menü, nicht zwischen die Filter.
        $this->assertStringContainsString('context.viewSlot || context.pluginSlot', $group);
    }

    public function testTheMemberListCountsMembers(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/users/manage.twig');

        // Im Archiv zählt die Leiste "60 archivierte Mitglieder" - passend zur Überschrift.
        $this->assertStringContainsString('data-count-one="{{ show_archived ? "archiviertes Mitglied" : "Mitglied" }}"', $twig);
        $this->assertStringContainsString('data-count-many="{{ show_archived ? "archivierte Mitglieder" : "Mitglieder" }}"', $twig);
        $this->assertStringContainsString('data-count-of="{{ show_archived ? "archivierten Mitgliedern" : "Mitgliedern" }}"', $twig);
    }
}
