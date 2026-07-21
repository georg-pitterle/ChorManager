<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\MailQueueAdminService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Task 5 switched the live navbar to `navigation(activeNav)`, a Twig function
 * wired in src/Dependencies.php, and collapsed templates/layout.twig to
 * `{% set navigation = navigation(active_nav|default("")) %}` plus a single
 * include of partials/navigation/menu.twig. Nothing rendered the real
 * layout.twig end-to-end afterwards: NavigationMenuRenderFeatureTest renders
 * menu.twig standalone from a hand-built tree, and separately greps
 * layout.twig's source for the include substring. If the variable handed
 * from layout.twig to menu.twig were ever renamed, or the include dropped,
 * the navbar would render empty while the whole suite stayed green.
 *
 * This test renders a real controller response through the real layout.twig
 * (extended by dashboard/index.twig) with the `navigation` Twig function
 * wired the same way src/Dependencies.php wires it (backed by the real
 * NavigationBuilder + NavigationContext::fromSession), to pin that seam
 * behaviorally for a plain member with the registration module enabled.
 */
class NavigationLayoutSeamFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['user_id' => 1];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testDashboardRendersRegistrationNavLinkThroughRealLayout(): void
    {
        $settings = ['modules' => ['registration' => true]];

        $controller = new DashboardController(
            $this->createTwig($settings),
            new MailQueueAdminService(),
            $settings
        );

        $request = $this->makeRequest('GET', '/dashboard');
        $response = $controller->index($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('href="/registrations"', $body);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createTwig(array $settings): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('settings', $settings);
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static function (string $path): string {
                return $path;
            }
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = '') use ($settings): array {
                $context = NavigationContext::fromSession($_SESSION, $settings, '/dashboard', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }
}
