<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class NewsletterFeatureFlagTest extends TestCase
{
    public function testSettingsExposeNewsletterFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'newsletter'\\s*=>\\s*EnvHelper::read\\('FEATURE_NEWSLETTER', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testNewsletterRoutesAreRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['newsletter'] ?? false) {");
        $this->assertNotFalse($gatePos, 'Newsletter feature gate missing in Routes.php.');

        foreach (["'/newsletters/archive'", "'/newsletters'", "'/newsletters/templates'"] as $route) {
            $routePos = strpos($content, $route);
            $this->assertNotFalse($routePos, "Newsletter route $route missing in Routes.php.");
            $this->assertGreaterThan($gatePos, $routePos, "Newsletter route $route must be inside the feature gate.");
        }
    }

    public function testNewsletterRouteRegistrationRespectsFeatureFlag(): void
    {
        $this->assertSame([], $this->registeredNewsletterRoutePatterns(false));

        $enabledPatterns = $this->registeredNewsletterRoutePatterns(true);
        $this->assertContains('/newsletters', $enabledPatterns);
        $this->assertContains('/newsletters/archive', $enabledPatterns);
        $this->assertContains('/newsletters/{id:[0-9]+}/preview', $enabledPatterns);
        $this->assertContains('/newsletters/templates', $enabledPatterns);
    }

    /**
     * Baut eine minimale echte Slim-App (ohne DB/Session/Middleware) und registriert die
     * Routen aus src/Routes.php, um im echten RouteCollector zu pruefen, welche
     * Newsletter-Routen abhaengig vom Feature-Flag existieren.
     *
     * Hinweis: AppFactory::setContainer() mutiert Slims prozess-globalen Factory-Zustand,
     * genau wie in WebmailFeatureFlagTest. Beide Tests setzen den Container selbst.
     *
     * @return string[]
     */
    private function registeredNewsletterRoutePatterns(bool $newsletterEnabled): array
    {
        $settings = ['modules' => ['newsletter' => $newsletterEnabled]];

        $container = new class ($settings) implements \Psr\Container\ContainerInterface {
            public function __construct(private array $settings)
            {
            }

            public function get(string $id): mixed
            {
                return $id === 'settings' ? $this->settings : null;
            }

            public function has(string $id): bool
            {
                return $id === 'settings';
            }
        };

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $routes = require dirname(__DIR__, 2) . '/src/Routes.php';
        $routes($app);

        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/newsletters')) {
                $patterns[] = $route->getPattern();
            }
        }

        return array_values(array_unique($patterns));
    }

    public function testNavigationGatesNewsletterLinksBehindFeatureFlag(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Navigation/NavigationBuilder.php');
        $this->assertIsString($content);

        // Beide Links (Archiv + Verwaltung) muessen hinter dem Newsletter-Feature-Flag liegen.
        $this->assertStringContainsString("'url' => '/newsletters/archive'", $content);
        $this->assertStringContainsString("'url' => '/newsletters'", $content);
        $this->assertSame(2, substr_count($content, "\$c->module('newsletter')"));
    }

    public function testDashboardControllerGatesNewsletterWidgetBehindFeatureFlag(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');
        $this->assertIsString($content);

        // Der Controller darf den Newsletter-Bereich nur bei aktivem Modul-Flag befuellen.
        $this->assertStringContainsString("['modules']['newsletter']", $content);
    }

    public function testDashboardTemplateGatesNewsletterModalAndScript(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString('{% if settings.modules.newsletter %}', $template);

        // Modal und Script duerfen nur bei aktivem Flag ausgeliefert werden.
        $gatePos = strpos($template, '{% if settings.modules.newsletter %}');
        $modalPos = strpos($template, 'id="newsletterActionModal"');
        $scriptPos = strpos($template, '/js/newsletters.js');

        $this->assertNotFalse($modalPos);
        $this->assertNotFalse($scriptPos);
        $this->assertGreaterThan($gatePos, $modalPos, 'Newsletter modal must be inside the feature gate.');
    }

    public function testRolesTemplateGatesNewsletterPermissionUi(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        // Matrix-Zeile plus Checkboxen im Create- und Edit-Modal: drei Gates noetig.
        $gateCount = substr_count($template, '{% if settings.modules.newsletter %}');
        $this->assertGreaterThanOrEqual(
            3,
            $gateCount,
            'Newsletter permission UI (matrix row, create checkbox, edit checkbox) must be feature-gated.'
        );
    }

    public function testRolesJsToleratesFeatureGatedCheckboxes(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/../public/js/roles.js');
        $this->assertIsString($js);

        // Feature-Flags koennen Checkboxen ausblenden; direkte .checked-Zuweisung
        // auf getElementById(...) wirft dann TypeError und bricht das Edit-Modal.
        $this->assertDoesNotMatchRegularExpression(
            '/document\.getElementById\([^)]*\)\.checked\s*=/',
            $js,
            'roles.js must not assign .checked on a possibly missing element.'
        );
    }

    public function testEnvExamplesAndProdComposeDocumentFeatureNewsletter(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_NEWSLETTER=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_NEWSLETTER=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_NEWSLETTER: ${FEATURE_NEWSLETTER:-false}', $compose);
    }
}
