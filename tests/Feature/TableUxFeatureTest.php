<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TableUxFeatureTest extends TestCase
{
    public function testSharedTableAssetsAndToolbarExist(): void
    {
        $layoutContent = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('/js/table-preferences.js', $layoutContent);
        $this->assertStringContainsString('/js/table-engine.js', $layoutContent);
        $this->assertStringContainsString('/css/table-engine.css', $layoutContent);
        $this->assertStringNotContainsString('/css/responsive-tables.css', $layoutContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../public/js/table-engine.js'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../public/js/table-preferences.js'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../public/css/table-engine.css'));
    }

    public function testSharedToolbarExposesAutoCardsAndTableModes(): void
    {
        $toolbarContent = file_get_contents(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig');

        $this->assertIsString($toolbarContent);
        // View toggle is now integrated into toolbar with ms-auto for right alignment
        $this->assertStringContainsString('data-table-mode="auto"', $toolbarContent);
        $this->assertStringContainsString('data-table-view="cards"', $toolbarContent);
        $this->assertStringContainsString('data-table-view="table"', $toolbarContent);
        $this->assertStringContainsString('>Auto<', $toolbarContent);
        $this->assertStringContainsString('data-table-view-toggle', $toolbarContent);
        $this->assertStringContainsString('ms-auto', $toolbarContent);
    }

    public function testSharedToolbarExposesSearchResetAndPaginationControls(): void
    {
        $toolbarContent = file_get_contents(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig');

        $this->assertIsString($toolbarContent);
        $this->assertStringContainsString('data-table-search', $toolbarContent);
        $this->assertStringContainsString('data-table-plugin-slot', $toolbarContent);
        $this->assertStringContainsString('data-table-reset', $toolbarContent);
        $this->assertStringContainsString('data-table-pagination', $toolbarContent);
        $this->assertStringContainsString('data-table-page-size', $toolbarContent);
        $this->assertStringContainsString('data-table-page-prev', $toolbarContent);
        $this->assertStringContainsString('data-table-page-next', $toolbarContent);
        $this->assertStringContainsString('data-table-page-label', $toolbarContent);
        $this->assertStringContainsString('>Zurücksetzen<', $toolbarContent);
    }

    public function testSharedToolbarExposesResultCountForFilteredRows(): void
    {
        $toolbarContent = file_get_contents(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig');

        $this->assertIsString($toolbarContent);
        $this->assertStringContainsString('data-table-result-count', $toolbarContent);
    }

    public function testSearchKeepsRawInputAndDoesNotWriteBackWhileTyping(): void
    {
        $engineContent = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engineContent);
        // Die Eingabe wird nicht beim Tippen normalisiert (verschluckte Leerzeichen) ...
        $this->assertStringNotContainsString('state.searchQuery = normalizeText(searchInput.value)', $engineContent);
        $this->assertStringContainsString('state.searchQuery = searchInput.value;', $engineContent);
        // ... und wird nur dann ins Feld zurückgeschrieben, wenn es vom Zustand abweicht.
        $this->assertStringContainsString('if (searchInput.value !== state.searchQuery)', $engineContent);
    }

    public function testSharedToolbarOffersFilterClearAndKeepsViewSwitchInMenu(): void
    {
        $toolbarContent = file_get_contents(dirname(__DIR__) . '/../templates/partials/table_toolbar.twig');

        $this->assertIsString($toolbarContent);
        $this->assertStringContainsString('data-table-filter-clear', $toolbarContent);
        $this->assertStringContainsString('data-table-filter-toggle', $toolbarContent);
        // Der Ansichtsschalter steckt im Menü „Ansicht“, nicht mehr als Knopfgruppe.
        $this->assertStringContainsString('>Ansicht<', $toolbarContent);
        $this->assertStringNotContainsString('btn-group ms-auto', $toolbarContent);
        $this->assertStringContainsString('pro Seite</option>', $toolbarContent);
    }

    public function testEngineShowsNoResultsRowAndNeverBuildsHtmlFromHeaderText(): void
    {
        $engineContent = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engineContent);
        $this->assertStringContainsString('Keine Treffer für', $engineContent);
        $this->assertStringContainsString('Filter zurücksetzen', $engineContent);
        // Sortierbezeichnungen stammen aus dem Tabellenkopf und gehen nie als HTML ins Panel.
        $this->assertStringNotContainsString("getSortLabel(sortSpec.key) + '</span>'", $engineContent);
    }

    public function testCardViewHasTitleFieldAndNoStripedCards(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/../public/css/table-engine.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('td[data-card-title]', $css);
        $this->assertStringContainsString('td[data-card-block]', $css);
        $this->assertStringContainsString('td[data-card-empty]', $css);
        $this->assertStringContainsString('--bs-table-bg-type: transparent', $css);
        // White-Label: Sortier-Badge folgt der Vereinsfarbe, nicht Bootstrap-Blau.
        $this->assertStringNotContainsString('13, 110, 253', $css);
        $this->assertStringNotContainsString('#0d6efd', $css);

        $eventsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/events/index.twig');
        $this->assertIsString($eventsTemplate);
        $this->assertStringContainsString('data-label="Titel" data-card-title', $eventsTemplate);
        $this->assertStringContainsString('badge text-bg-{{ event.type_color }}', $eventsTemplate);
    }

    public function testUsersManagePluginHasNoOwnResetButton(): void
    {
        $pluginContent = file_get_contents(
            dirname(__DIR__) . '/../public/js/table-plugins/users-manage-plugin.js'
        );

        $this->assertIsString($pluginContent);
        $this->assertStringNotContainsString('createResetControl', $pluginContent);
        $this->assertStringNotContainsString('Reset', $pluginContent);
    }

    public function testUsersManagePluginAssetIsLoadedFromLayout(): void
    {
        $layoutContent = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('/js/table-plugins/users-manage-plugin.js', $layoutContent);
    }

    public function testUsersManageTableDeclaresPluginAndSortableColumns(): void
    {
        $usersTemplate = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');

        $this->assertIsString($usersTemplate);
        $this->assertStringNotContainsString('data-users-manage-filter-slot', $usersTemplate);
        $this->assertStringContainsString('data-table-plugins="usersManage,usersGroup"', $usersTemplate);
        $this->assertStringContainsString('data-voice-options="{{ voice_options_attr|replace({\'\\n\': \'\', \'\\r\': \'\', \'\\t\': \' \'})|trim }}"', $usersTemplate);
        $this->assertStringContainsString('data-project-options="{{ project_options_attr|replace({\'\\n\': \'\', \'\\r\': \'\', \'\\t\': \' \'})|trim }}"', $usersTemplate);
        $this->assertStringContainsString('data-sort-key="name"', $usersTemplate);
        $this->assertStringContainsString('data-sort-key="email"', $usersTemplate);
        $this->assertStringContainsString('data-role="{{ role_filter_ids }}"', $usersTemplate);
        $this->assertStringContainsString('data-voice="{{ voice_filter_ids }}"', $usersTemplate);
        $this->assertStringContainsString('data-project="{{ project_filter_ids }}"', $usersTemplate);
    }

    public function testAllTableEngineContainersDeclareDefaultPageSize100(): void
    {
        $templates = [
            'templates/users/manage.twig',
            'templates/finances/index.twig',
            'templates/evaluations/index.twig',
            'templates/events/index.twig',
            // songs/downloads.twig nicht: Die Seite hat keine Tabelle mehr, sondern je Lied
            // eine Dateiliste (siehe DownloadsPageLayoutFeatureTest).
            'templates/sponsoring/dashboard.twig',
            'templates/projects/index.twig',
            'templates/projects/members.twig',
            'templates/projects/tasks.twig',
            'templates/sponsoring/sponsors/index.twig',
        ];

        foreach ($templates as $template) {
            $content = file_get_contents(dirname(__DIR__) . '/../' . $template);
            $this->assertIsString($content, $template);
            $this->assertStringContainsString('data-default-page-size="100"', $content, $template);
            $this->assertStringContainsString('data-page-size-options="25,50,100,200"', $content, $template);
        }
    }

    public function testAllTableEngineContainersIncludeViewToggle(): void
    {
        $templates = [
            'templates/users/manage.twig',
            'templates/finances/index.twig',
            'templates/evaluations/index.twig',
            'templates/events/index.twig',
            'templates/sponsoring/dashboard.twig',
            'templates/projects/index.twig',
            'templates/projects/members.twig',
            'templates/projects/tasks.twig',
            'templates/sponsoring/sponsors/index.twig',
        ];

        foreach ($templates as $template) {
            $content = file_get_contents(dirname(__DIR__) . '/../' . $template);
            $this->assertIsString($content, $template);
            // View-toggle is now integrated in toolbar, NOT a separate include
            $this->assertStringNotContainsString('table_view_toggle.twig', $content, "Template $template should not include view-toggle separately anymore");
        }
    }

    public function testTask4FixedTemplatesExposeCorrectSortKeys(): void
    {
        $projectMembersTemplate = file_get_contents(dirname(__DIR__) . '/../templates/projects/members.twig');
        $this->assertIsString($projectMembersTemplate);
        $this->assertStringContainsString('data-sort-key="email"', $projectMembersTemplate);
        $this->assertStringNotContainsString('data-sort-key="role" data-sort-type="text">E-Mail</th>', $projectMembersTemplate);

        $sponsorsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/sponsoring/sponsors/index.twig');
        $this->assertIsString($sponsorsTemplate);
        $this->assertStringContainsString('data-sort-key="sponsorship_count" data-sort-type="number"', $sponsorsTemplate);
        $this->assertStringContainsString('data-sort-key="sponsorship_count"', $sponsorsTemplate);
        $this->assertStringContainsString('data-sort-value="{{ sponsor.sponsorships|length }}"', $sponsorsTemplate);
        $this->assertStringNotContainsString('data-sort-key="last_contact_date" data-sort-type="date">Vereinbarungen</th>', $sponsorsTemplate);

        $evaluationsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/evaluations/index.twig');
        $this->assertIsString($evaluationsTemplate);
        $this->assertStringContainsString('data-sort-key="excused"', $evaluationsTemplate);
        $this->assertStringContainsString('data-sort-key="unexcused"', $evaluationsTemplate);
        $this->assertStringContainsString('data-sort-key="percentage"', $evaluationsTemplate);
        $this->assertStringNotContainsString('data-sort-key="excused_count"', $evaluationsTemplate);
        $this->assertStringNotContainsString('data-sort-key="unexcused_count"', $evaluationsTemplate);
    }

    public function testAllTableEngineContainersHaveDefaultSortKey(): void
    {
        $templates = [
            'templates/users/manage.twig',
            'templates/finances/index.twig',
            'templates/evaluations/index.twig',
            'templates/events/index.twig',
            'templates/sponsoring/dashboard.twig',
            'templates/projects/index.twig',
            'templates/projects/members.twig',
            'templates/projects/tasks.twig',
            'templates/sponsoring/sponsors/index.twig',
        ];

        foreach ($templates as $template) {
            $content = file_get_contents(dirname(__DIR__) . '/../' . $template);
            $this->assertIsString($content, $template);
            $this->assertStringContainsString('data-default-sort-key=', $content, "Table engine in $template must declare a data-default-sort-key attribute");
        }
    }

    public function testUsersManageTableUsesProjectCountSortAndModalTrigger(): void
    {
        $usersTemplate = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');

        $this->assertIsString($usersTemplate);
        $this->assertStringContainsString('data-sort-key="project_count"', $usersTemplate);
        $this->assertStringContainsString('data-sort-type="number"', $usersTemplate);
        $this->assertStringContainsString('data-sort-initial-dir="desc">Projekte</th>', $usersTemplate);
        $this->assertStringContainsString('data-sort-project_count="{{ user.project_count }}"', $usersTemplate);
        $this->assertStringContainsString('data-bs-target="#userProjectsModal{{ user.id }}"', $usersTemplate);
        $this->assertStringContainsString('Keine Projektteilnahmen vorhanden.', $usersTemplate);
    }

    public function testTableEngineSupportsPerColumnInitialSortDirection(): void
    {
        $engineContent = file_get_contents(dirname(__DIR__) . '/../public/js/table-engine.js');

        $this->assertIsString($engineContent);
        $this->assertStringContainsString(
            'const initialSortDir = normalizeSortDir(header.dataset.sortInitialDir);',
            $engineContent
        );
        $this->assertStringContainsString('nextSortColumns.push({ key: key, dir: initialSortDir });', $engineContent);
        $this->assertStringContainsString('setSortColumns([{ key: key, dir: initialSortDir }]);', $engineContent);
    }

    public function testUsersGroupPluginAssetIsLoadedFromLayout(): void
    {
        $layoutContent = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('/js/table-plugins/users-group-plugin.js', $layoutContent);
    }

    public function testUsersManageTableDeclaresGroupPlugin(): void
    {
        $usersTemplate = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');

        $this->assertIsString($usersTemplate);
        $this->assertStringContainsString('data-table-plugins="usersManage,usersGroup"', $usersTemplate);
        $this->assertStringContainsString('data-sub-voice-options=', $usersTemplate);
        $this->assertStringContainsString('data-show-archived=', $usersTemplate);
    }

    public function testSponsorsIndexUsesPluginFirstFilteringWithoutServerFilterForm(): void
    {
        $sponsorsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/sponsoring/sponsors/index.twig');

        $this->assertIsString($sponsorsTemplate);
        $this->assertStringContainsString('data-table-plugins="sponsorState"', $sponsorsTemplate);
        $this->assertStringNotContainsString('method="get" action="/sponsoring/sponsors"', $sponsorsTemplate);
    }

    public function testSongsManageUsesPluginFirstFilteringWithoutGetFilterForm(): void
    {
        $songsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/songs/manage.twig');

        $this->assertIsString($songsTemplate);
        $this->assertStringContainsString('data-table-plugins="songCategory"', $songsTemplate);
        $this->assertStringNotContainsString('method="get" action="/song-library"', $songsTemplate);
    }
}
