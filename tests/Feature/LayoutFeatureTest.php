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

    public function testCssDefinesHeaderAndListheadTokens(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('--header-bg-start:', $styleContent);
        $this->assertStringContainsString('--header-bg-end:', $styleContent);
        $this->assertStringContainsString('--header-accent-line:', $styleContent);
        $this->assertStringContainsString('--listhead-bg:', $styleContent);
        $this->assertStringContainsString('--listhead-text:', $styleContent);
        $this->assertStringContainsString('--listhead-border:', $styleContent);
    }

    public function testTopbarCssUsesGradientWithAccentBorder(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        // Must use a gradient instead of a flat rgba() background
        $this->assertStringContainsString('linear-gradient(135deg', $styleContent);
        // Accent border must reference the header token
        $this->assertStringContainsString('3px solid var(--header-accent-line', $styleContent);
        // Must carry explicit box-shadow for depth
        $this->assertStringContainsString('box-shadow: 0 2px 16px rgba(0, 0, 0, 0.4)', $styleContent);
    }

    public function testCssDefinesNavActiveChipStyle(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        // Active link must have a primary-tinted background chip
        $this->assertStringContainsString(
            'background: rgba(var(--theme-primary-rgb), 0.12)',
            $styleContent
        );
        // Must be visually contained with a border-radius
        $this->assertStringContainsString('border-radius: 0.375rem', $styleContent);
    }

    public function testCssDefinesListheadHarmonizationRules(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        // All three thead variants must be targeted
        $this->assertStringContainsString('thead.table-dark', $styleContent);
        $this->assertStringContainsString('thead.table-light', $styleContent);
        $this->assertStringContainsString('thead:not([class])', $styleContent);
        // Must use listhead tokens
        $this->assertStringContainsString('var(--listhead-bg', $styleContent);
        $this->assertStringContainsString('var(--listhead-text', $styleContent);
        // Accent bottom-border on thead
        $this->assertMatchesRegularExpression(
            '/thead\.(table-dark|table-light).*border-bottom: 3px solid var\(--header-accent-line/s',
            $styleContent
        );
        // Typographic treatment on th cells
        $this->assertStringContainsString('text-transform: uppercase', $styleContent);
        $this->assertStringContainsString('letter-spacing: 0.04em', $styleContent);
    }

    public function testCssDefinesCardHeaderHarmonizationRule(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        // Global selector with semantic guards must exist
        $this->assertStringContainsString(
            '.card > .card-header:not(.bg-success):not(.bg-danger):not(.bg-warning)',
            $styleContent
        );
        // Must use the listhead background token with !important to beat Bootstrap utilities
        $this->assertStringContainsString(
            'var(--listhead-bg, #eef1f5) !important',
            $styleContent
        );
        // Must carry the accent bottom border
        $this->assertStringContainsString(
            'border-bottom: 3px solid var(--header-accent-line',
            $styleContent
        );
    }

    public function testTemplateCardHeadersDoNotCarryMisleadingDarkClasses(): void
    {
        $base = dirname(__DIR__) . '/..';
        $templates = [
            $base . '/templates/attendance/show.twig',
            $base . '/templates/evaluations/project_members.twig',
            $base . '/templates/profile/index.twig',
        ];

        foreach ($templates as $path) {
            $content = file_get_contents($path);
            $this->assertIsString($content);
            $this->assertStringNotContainsString(
                'card-header bg-dark',
                $content,
                basename($path) . ' still carries card-header bg-dark'
            );
            $this->assertStringNotContainsString(
                'card-header bg-secondary',
                $content,
                basename($path) . ' still carries card-header bg-secondary'
            );
        }
    }
}
