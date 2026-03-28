<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class LayoutFeatureTest extends TestCase
{
    public function testLayoutContainsResponsiveTopbarBrandAndTogglerMarkup(): void
    {
        $layoutPath = dirname(__DIR__) . '/../templates/layout.twig';
        $layoutContent = file_get_contents($layoutPath);

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('class="app-topbar__brand-name"', $layoutContent);
        $this->assertStringContainsString('class="navbar-toggler"', $layoutContent);
        $this->assertStringContainsString('class="bi bi-list fs-3 text-white"', $layoutContent);
        $this->assertStringContainsString('navbar-expand-lg', $layoutContent);
    }

    public function testTopbarCssDoesNotForceHideBrandNameOnSmallScreens(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $styleContent);
        $this->assertStringNotContainsString(".app-topbar__brand-name {\n        display: none;", $styleContent);
    }

    public function testTopbarCssDefinesVisibleTogglerIconStyling(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('.navbar.bg-dark.app-topbar .navbar-toggler .bi-list', $styleContent);
        $this->assertStringContainsString('line-height: 1;', $styleContent);
    }

    public function testTopbarCssDoesNotOverrideBootstrapTogglerVisibility(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('.navbar.bg-dark.app-topbar .navbar-toggler {', $styleContent);
        $this->assertStringNotContainsString('display: inline-flex;', $styleContent);
        $this->assertStringNotContainsString('display: none !important;', $styleContent);
    }

    public function testPageHeaderCssWrapsActionsToAvoidHorizontalOverflow(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('.page-header .page-actions', $styleContent);
        $this->assertStringContainsString('flex-wrap: wrap;', $styleContent);
        $this->assertStringContainsString('max-width: 100%;', $styleContent);
    }
}
