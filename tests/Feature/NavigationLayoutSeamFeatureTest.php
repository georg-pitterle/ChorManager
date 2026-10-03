<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Policies\TaskPolicy;
use App\Services\MailQueueAdminService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * templates/layout.twig builds the menu via `navigation(activeNav)`, a Twig
 * function wired in src/Dependencies.php, and hands the result to
 * partials/navigation/sidebar.twig; it also loads the sidebar scripts and the
 * quick-search modal. NavigationMenuRenderFeatureTest renders sidebar.twig
 * standalone from a built tree, so only a render of the real layout notices
 * when the variable handed over is renamed or an include or script is dropped -
 * the sidebar would render empty while the rest of the suite stayed green.
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
    use TwigViewStubs;

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
            new TaskPolicy($_SESSION),
            $settings
        );

        $request = $this->makeRequest('GET', '/dashboard');
        $response = $controller->index($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('href="/registrations"', $body);
        $this->assertStringContainsString('id="app-sidebar"', $body);
        $this->assertStringContainsString('data-nav-toggle', $body);
        $this->assertStringContainsString('class="app-shell app-shell--with-sidebar"', $body);
        $this->assertMatchesRegularExpression(
            '/<head>.*<script src="\/js\/navigation-state\.js"><\/script>.*<\/head>/s',
            $body
        );
        $this->assertStringContainsString('<script src="/js/navigation.js"></script>', $body);
        $this->assertStringContainsString('id="nav-search-modal"', $body);
        $this->assertMatchesRegularExpression('/<button[^>]*data-nav-search-open[^>]*aria-controls="nav-search-modal"/', $body);
        $this->assertMatchesRegularExpression(
            '/<script src="\/js\/navigation-search-rank\.js"><\/script>\s*<script src="\/js\/navigation-search\.js"><\/script>/',
            $body
        );
    }

    public function testLoggedOutPagesRenderWithoutSidebar(): void
    {
        $_SESSION = [];
        $twig = $this->createTwig([]);

        $html = $twig->getEnvironment()->render('layout.twig', []);

        $this->assertStringNotContainsString('id="app-sidebar"', $html);
        $this->assertStringContainsString('<body class="app-shell">', $html);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createTwig(array $settings): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addFilter(new \Twig\TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new \App\Services\NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('settings', $settings);
        $environment->addGlobal('session', $_SESSION);
        $this->registerMailBadgeStub($environment);
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
