<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class LayoutFeatureTest extends TestCase
{
    public function testLayoutContainsTopbarBrandAndSidebarToggle(): void
    {
        $layoutPath = dirname(__DIR__) . '/../templates/layout.twig';
        $layoutContent = file_get_contents($layoutPath);

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('class="app-topbar__brand-name"', $layoutContent);
        $this->assertMatchesRegularExpression(
            '/class="app-topbar__menu-toggle"\s+data-nav-toggle\s+aria-controls="app-sidebar"/',
            $layoutContent
        );
        $this->assertStringContainsString('<i class="bi bi-list" aria-hidden="true"></i>', $layoutContent);
        // Die Menüpunkte stecken nicht mehr im Navbar-Collapse, sondern in der Seitenleiste.
        $this->assertStringNotContainsString('navbar-toggler', $layoutContent);
        $this->assertStringNotContainsString('navbar-expand-lg', $layoutContent);
    }

    public function testTopbarCssDoesNotForceHideBrandNameOnSmallScreens(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $styleContent);
        $this->assertStringNotContainsString(".app-topbar__brand-name {\n        display: none;", $styleContent);
    }

    public function testTopbarCssDefinesVisibleMenuToggleStyling(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertMatchesRegularExpression(
            '/\.app-topbar__menu-toggle \{[^}]*display: inline-flex;[^}]*color: #ffffff;/s',
            $styleContent
        );
        $this->assertMatchesRegularExpression('/\.app-topbar__actions \{[^}]*margin-left: auto;/s', $styleContent);
    }

    public function testSidebarCssPlacesSidebarBesideContentFromLg(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);

        $start = strpos($styleContent, '@media (min-width: 992px) {' . "
" . '    .app-sidebar.offcanvas-lg {');
        $this->assertNotFalse($start, 'Ab lg muss die Seitenleiste fest neben dem Inhalt stehen.');
        $block = substr($styleContent, $start, 2500);

        $this->assertStringContainsString('position: fixed;', $block);
        $this->assertStringContainsString('margin-left: var(--app-sidebar-width);', $block);
        $this->assertStringContainsString('html.nav-collapsed .app-sidebar.offcanvas-lg', $block);
        $this->assertStringContainsString('width: var(--app-sidebar-rail-width);', $block);
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
        // Der aktive Leisteneintrag trägt einen Chip in der Themenfarbe.
        $this->assertMatchesRegularExpression(
            '/\.app-sidebar__link\.active \{[^}]*background: rgba\(var\(--theme-primary-rgb\), 0\.16\);'
                . '[^}]*color: var\(--theme-primary, #E8A817\);/s',
            $styleContent
        );
        $this->assertMatchesRegularExpression('/\.app-sidebar__link \{[^}]*border-radius: 0\.375rem;/s', $styleContent);
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
        // Tabellenköpfe nutzen die Gruppenlabel-Typografie aus DESIGN.md (700, 0,06em, 0,75rem).
        $this->assertMatchesRegularExpression(
            '/thead th \{[^}]*font-weight: 700;[^}]*letter-spacing: 0\.06em;[^}]*font-size: 0\.75rem;/s',
            $styleContent
        );
    }

    public function testDashboardShellColumnMayShrinkBelowContentWidth(): void
    {
        $styleContent = file_get_contents(dirname(__DIR__) . '/../public/css/style.css');

        $this->assertIsString($styleContent);
        // Sonst bläht eine breite Tabelle den Inhaltsbereich über den Viewport auf und die
        // Auto-Ansicht der Table-Engine hält sie für passend.
        $this->assertMatchesRegularExpression(
            '/\.dashboard-shell \{[^}]*grid-template-columns: minmax\(0, 1fr\);/s',
            $styleContent
        );
    }

    public function testCssDefinesCardHeaderHarmonizationRule(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        // Global selector with semantic guards must exist
        $this->assertStringContainsString(
            '.card>.card-header:not(.bg-success):not(.bg-danger):not(.bg-warning)',
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

    public function testCssDefinesNarrowViewportListheadRefinements(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        // A narrow-viewport thead th override must exist
        // Verify by checking for the exact font-size reduction value
        $this->assertStringContainsString('font-size: 0.75rem', $styleContent);
        // The responsive card-header padding reduction must exist
        $this->assertStringContainsString('padding: 0.625rem 1rem', $styleContent);
        // Confirm the narrow styles live inside a 767.98px media query
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\).*font-size: 0\.75rem/s',
            $styleContent
        );
    }

    public function testEventsTableUsesClientSideSortableHeaders(): void
    {
        $eventsTemplatePath = dirname(__DIR__) . '/../templates/events/index.twig';
        $eventsTemplateContent = file_get_contents($eventsTemplatePath);

        $this->assertIsString($eventsTemplateContent);
        $this->assertStringNotContainsString('text-white text-decoration-none', $eventsTemplateContent);
        $this->assertStringNotContainsString('/events?{{', $eventsTemplateContent);
        $this->assertStringContainsString(
            '<th data-sort-key="starts_at" data-sort-type="date">Datum / Zeit</th>',
            $eventsTemplateContent
        );
    }

    public function testLayoutUsesNavbarLogoClassWithoutFixedHeightAttribute(): void
    {
        $layoutPath = dirname(__DIR__) . '/../templates/layout.twig';
        $layoutContent = file_get_contents($layoutPath);

        $this->assertIsString($layoutContent);
        $this->assertStringContainsString('class="me-2 navbar-logo"', $layoutContent);
        $this->assertStringNotContainsString('src="/logo" alt="Logo" height="36"', $layoutContent);
    }

    public function testTopbarCssDefinesNavbarLogoProportionRules(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('.navbar-logo {', $styleContent);
        $this->assertStringContainsString('height: 36px;', $styleContent);
        $this->assertStringContainsString('width: auto;', $styleContent);
        $this->assertStringContainsString('object-fit: contain;', $styleContent);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\).*\.navbar-logo \{\s*height: 32px;/s',
            $styleContent
        );
    }

    public function testSettingsTemplateUsesClassBasedLogoPreviewWithoutInlineStyles(): void
    {
        $settingsPath = dirname(__DIR__) . '/../templates/settings/index.twig';
        $settingsContent = file_get_contents($settingsPath);

        $this->assertIsString($settingsContent);
        $this->assertStringContainsString('logo-preview-frame', $settingsContent);
        $this->assertStringContainsString('settings-logo-preview', $settingsContent);
        $this->assertStringContainsString('class="mb-2 d-inline-block logo-preview"', $settingsContent);
        $this->assertStringNotContainsString('alt="Aktuelles Logo" style=', $settingsContent);
    }

    public function testCssDefinesSettingsLogoPreviewProportionRules(): void
    {
        $stylePath = dirname(__DIR__) . '/../public/css/style.css';
        $styleContent = file_get_contents($stylePath);

        $this->assertIsString($styleContent);
        $this->assertStringContainsString('.logo-preview-frame {', $styleContent);
        $this->assertStringContainsString('min-width: 120px;', $styleContent);
        $this->assertStringContainsString('.logo-preview {', $styleContent);
        $this->assertStringContainsString('max-height: 80px;', $styleContent);
        $this->assertStringContainsString('width: auto;', $styleContent);
        $this->assertStringContainsString('height: auto;', $styleContent);
        $this->assertStringContainsString('aspect-ratio: auto;', $styleContent);
    }
}
