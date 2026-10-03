<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class NavigationMenuRenderFeatureTest extends TestCase
{
    private function render(array $permissions, array $modules, string $path): string
    {
        $tree = (new NavigationBuilder())->build(
            new NavigationContext($permissions, $modules, $path)
        );

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));

        return $twig->render('partials/navigation/sidebar.twig', ['navigation' => $tree]);
    }

    public function testPlainMemberSidebarHasPublicLinksOnly(): void
    {
        $html = $this->render([], ['registration' => true], '/dashboard');

        $this->assertStringContainsString('href="/registrations"', $html);
        $this->assertStringContainsString('href="/downloads"', $html);
        $this->assertStringContainsString('href="/evaluations/project-members"', $html);
        $this->assertStringNotContainsString('href="/roles"', $html);
        $this->assertStringNotContainsString('href="/backups"', $html);
        $this->assertStringNotContainsString('data-nav-section="administration"', $html);
    }

    public function testSidebarIsAnOffcanvasBelowLg(): void
    {
        $html = $this->render([], [], '/dashboard');

        $this->assertMatchesRegularExpression(
            '/<aside[^>]*class="offcanvas-lg offcanvas-start app-sidebar"[^>]*id="app-sidebar"/',
            $html
        );
        $this->assertStringContainsString('data-bs-dismiss="offcanvas"', $html);
        $this->assertStringContainsString('aria-label="Hauptnavigation"', $html);
    }

    public function testActiveLinkIsMarkedForStylingAndScreenReaders(): void
    {
        $html = $this->render(['can_manage_users' => true], ['registration' => true], '/registrations');

        $this->assertMatchesRegularExpression(
            '/<a class="app-sidebar__link active"\s+href="\/registrations"[^>]*aria-current="page"/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testLinksCarrySearchData(): void
    {
        $html = $this->render(['can_read_finances' => true], ['finance' => true], '/dashboard');

        $this->assertMatchesRegularExpression(
            '/href="\/finances"[^>]*data-nav-icon="bi-bank"[^>]*data-nav-keywords="kassabuch\|geld\|[^"]*"/',
            $html
        );
        $this->assertStringContainsString('data-nav-section-title="Finanzen"', $html);
    }

    public function testAdministrationIsFoldableAndOpenWhenActive(): void
    {
        $closed = $this->render(['can_manage_backups' => true], [], '/dashboard');
        $this->assertMatchesRegularExpression(
            '/<button type="button"\s+class="app-sidebar__heading app-sidebar__toggle"'
                . '[^>]*aria-expanded="false"[^>]*data-nav-fold="administration"/',
            $closed
        );
        $this->assertStringNotContainsString('app-sidebar__section--foldable is-active', $closed);

        $open = $this->render(['can_manage_backups' => true], [], '/backups');
        $this->assertStringContainsString('app-sidebar__section--foldable is-active', $open);
        $this->assertMatchesRegularExpression('/aria-expanded="true"[^>]*data-nav-fold="administration"/', $open);
    }

    public function testHelpSitsBehindASpacerAtTheBottom(): void
    {
        $html = $this->render([], [], '/dashboard');

        $this->assertMatchesRegularExpression(
            '/<div class="app-sidebar__spacer"><\/div>\s*<a class="app-sidebar__link"\s+href="\/help"/',
            $html
        );
    }

    public function testWiringAndLayoutUseBuilder(): void
    {
        $deps = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($deps);
        $this->assertStringContainsString("'navigation'", $deps);
        $this->assertStringContainsString('NavigationBuilder', $deps);

        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $this->assertIsString($layout);
        $this->assertStringContainsString('include("partials/navigation/sidebar.twig"', $layout);
        $this->assertStringNotContainsString('navbarsExampleDefault', $layout);
        $this->assertStringNotContainsString('can_show_events', $layout);
        $this->assertStringNotContainsString('can_show_admin', $layout);
    }

    public function testOldNavPartialsRemoved(): void
    {
        foreach (['menu', 'events', 'areas', 'admin', 'evaluations', 'dashboard'] as $partial) {
            $this->assertFileDoesNotExist(
                dirname(__DIR__) . '/../templates/partials/navigation/' . $partial . '.twig'
            );
        }
    }
}
