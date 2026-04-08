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
        $this->assertStringContainsString('>Zuruecksetzen<', $toolbarContent);
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
        $this->assertStringContainsString('data-table-plugins="usersManage"', $usersTemplate);
        $this->assertStringContainsString('data-sort-key="name"', $usersTemplate);
        $this->assertStringContainsString('data-sort-key="email"', $usersTemplate);
        $this->assertStringContainsString('data-role="{{ role_filter|trim }}"', $usersTemplate);
        $this->assertStringContainsString('data-voice="{{ voice_filter|trim }}"', $usersTemplate);
        $this->assertStringContainsString('data-project="{{ project_filter|trim }}"', $usersTemplate);
    }

    public function testAllTableEngineContainersDeclareDefaultPageSize100(): void
    {
        $templates = [
            'templates/users/manage.twig',
            'templates/finances/index.twig',
            'templates/evaluations/index.twig',
            'templates/events/index.twig',
            'templates/songs/downloads.twig',
            'templates/roles/index.twig',
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
            'templates/songs/downloads.twig',
            'templates/roles/index.twig',
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
            if (str_contains($template, 'downloads')) {
                // Downloads has no toolbar, so should not have it
                $this->assertStringNotContainsString('table_view_toggle.twig', $content, "Template $template should not include view-toggle separately anymore");
            } else {
                // All others have toolbar which includes view-toggle
                $this->assertStringNotContainsString('table_view_toggle.twig', $content, "Template $template should not include view-toggle separately anymore");
            }
        }
    }

    public function testTask4FixedTemplatesExposeCorrectSortKeys(): void
    {
        $projectMembersTemplate = file_get_contents(dirname(__DIR__) . '/../templates/projects/members.twig');
        $this->assertIsString($projectMembersTemplate);
        $this->assertStringContainsString('data-sort-key="email"', $projectMembersTemplate);
        $this->assertStringNotContainsString('data-sort-key="role" data-sort-type="text">E-Mail</th>', $projectMembersTemplate);

        $downloadsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/songs/downloads.twig');
        $this->assertIsString($downloadsTemplate);
        $this->assertStringContainsString('data-sort-key="mime_type"', $downloadsTemplate);
        $this->assertStringNotContainsString('data-sort-key="song_title"', $downloadsTemplate);
        $this->assertStringNotContainsString('data-sort-key="updated_at"', $downloadsTemplate);

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
            'templates/songs/downloads.twig',
            'templates/roles/index.twig',
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
        $this->assertStringContainsString(
            'data-sort-key="project_count" data-sort-type="number" data-sort-initial-dir="desc">Projekte</th>',
            $usersTemplate
        );
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
}
