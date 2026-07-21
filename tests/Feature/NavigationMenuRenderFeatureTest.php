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

        return $twig->render('partials/navigation/menu.twig', ['navigation' => $tree]);
    }

    public function testPlainMemberMenuHtmlHasPublicLinksOnly(): void
    {
        $html = $this->render([], ['registration' => true], '/dashboard');

        $this->assertStringContainsString('href="/registrations"', $html);
        $this->assertStringContainsString('href="/downloads"', $html);
        $this->assertStringContainsString('href="/evaluations/project-members"', $html);
        $this->assertStringNotContainsString('href="/roles"', $html);
        $this->assertStringNotContainsString('href="/backups"', $html);
    }

    public function testActiveClassRenderedForCurrentPath(): void
    {
        $html = $this->render(['can_manage_users' => true], ['registration' => true], '/registrations');
        $this->assertMatchesRegularExpression('/class="dropdown-item active"[^>]*href="\/registrations"/', $html);
    }

    public function testWiringAndLayoutUseBuilder(): void
    {
        $deps = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($deps);
        $this->assertStringContainsString("'navigation'", $deps);
        $this->assertStringContainsString('NavigationBuilder', $deps);

        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $this->assertIsString($layout);
        $this->assertStringContainsString("include('partials/navigation/menu.twig'", $layout);
        $this->assertStringNotContainsString('can_show_events', $layout);
        $this->assertStringNotContainsString('can_show_admin', $layout);
    }

    public function testOldNavPartialsRemoved(): void
    {
        foreach (['events', 'areas', 'admin', 'evaluations', 'dashboard'] as $partial) {
            $this->assertFileDoesNotExist(
                dirname(__DIR__) . '/../templates/partials/navigation/' . $partial . '.twig'
            );
        }
    }
}
