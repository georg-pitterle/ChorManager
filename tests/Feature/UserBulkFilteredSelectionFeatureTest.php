<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Bulk-archive on /users must respect the active table filter: "select all" selects the
 * whole filtered set across pagination pages and never submits filtered-out members, with a
 * visible hint when the selection spans multiple pages.
 */
final class UserBulkFilteredSelectionFeatureTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/../' . $relativePath);
        $this->assertIsString($source, $relativePath . ' must be readable');

        return $source;
    }

    public function testTableEngineExposesFilteredSet(): void
    {
        $engine = $this->read('public/js/table-engine.js');

        // Additive hook: an event plus a snapshot so late listeners can read initial state.
        $this->assertStringContainsString("'chor-table:applied'", $engine);
        $this->assertStringContainsString('chorTableLastApplied', $engine);
        $this->assertStringContainsString('filteredRows: sortedRows', $engine);
    }

    public function testUsersJsBulkSelectionIsFilterAware(): void
    {
        $js = $this->read('public/js/users.js');

        // No more filter-agnostic "select every checkbox on the page".
        $this->assertStringNotContainsString(
            "const rowCheckboxes = Array.from(document.querySelectorAll('.user-row-select'))",
            $js
        );

        // Reacts to the engine's filtered set and keeps a selection independent of pagination.
        $this->assertStringContainsString("addEventListener('chor-table:applied'", $js);
        $this->assertStringContainsString('chorTableLastApplied', $js);
        $this->assertStringContainsString('selectedIds', $js);

        // Cross-page awareness: hint element, off-page detection and dynamic confirm message.
        $this->assertStringContainsString('bulkCrossPageHint', $js);
        $this->assertStringContainsString('anderen Seiten', $js);
        $this->assertStringContainsString('data-confirm', $js);
    }

    public function testManageTemplateHasCrossPageHintElement(): void
    {
        $twig = $this->read('templates/users/manage.twig');

        $this->assertStringContainsString('id="bulkCrossPageHint"', $twig);
    }
}
