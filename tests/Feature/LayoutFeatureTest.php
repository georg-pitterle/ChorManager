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
        $this->assertStringContainsString('navbar-expand-lg', $layoutContent);
    }

    public function testTopbarCssHidesBrandNameOnSmallScreens(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $styleContent);
        $this->assertStringContainsString('.app-topbar__brand-name', $styleContent);
        $this->assertStringContainsString('display: none;', $styleContent);
    }

    public function testTopbarCssDefinesExplicitVisibleTogglerIcon(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('.navbar.bg-dark.app-topbar .navbar-toggler-icon', $styleContent);
        $this->assertStringContainsString('background-image: url("data:image/svg+xml', $styleContent);
        $this->assertStringContainsString('rgba(255,255,255,0.95)', $styleContent);
    }
}
