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
        $this->assertStringContainsString('data-table-mode="auto"', $toolbarContent);
        $this->assertStringContainsString('data-table-view="cards"', $toolbarContent);
        $this->assertStringContainsString('data-table-view="table"', $toolbarContent);
        $this->assertStringContainsString('>Auto<', $toolbarContent);
    }
}
