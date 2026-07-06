<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class SponsoringFeatureFlagTest extends TestCase
{
    public function testSettingsExposeSponsoringFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'sponsoring'\\s*=>\\s*EnvHelper::read\\('FEATURE_SPONSORING', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testSponsoringRoutesAreRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['sponsoring'] ?? false) {");
        $this->assertNotFalse($gatePos, 'Sponsoring feature gate missing in Routes.php.');

        $routePos = strpos($content, "'/sponsoring'");
        $this->assertNotFalse($routePos, 'Sponsoring route group missing in Routes.php.');
        $this->assertGreaterThan($gatePos, $routePos, 'Sponsoring route group must be inside the feature gate.');
    }

    public function testSponsoringRouteRegistrationRespectsFeatureFlag(): void
    {
        $this->assertSame([], $this->registeredSponsoringRoutePatterns(false));

        $enabledPatterns = $this->registeredSponsoringRoutePatterns(true);
        $this->assertContains('/sponsoring', $enabledPatterns);
        $this->assertContains('/sponsoring/sponsors', $enabledPatterns);
        $this->assertContains('/sponsoring/packages', $enabledPatterns);
        $this->assertContains('/sponsoring/sponsorships', $enabledPatterns);
    }

    /**
     * Minimale echte Slim-App wie in den anderen FeatureFlag-Tests;
     * AppFactory::setContainer() wird von allen diesen Tests selbst gesetzt.
     *
     * @return string[]
     */
    private function registeredSponsoringRoutePatterns(bool $sponsoringEnabled): array
    {
        $settings = ['modules' => ['sponsoring' => $sponsoringEnabled]];

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
            if (str_starts_with($route->getPattern(), '/sponsoring')) {
                $patterns[] = $route->getPattern();
            }
        }

        return array_values(array_unique($patterns));
    }

    public function testNavigationGatesSponsoringLinkBehindFeatureFlag(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/areas.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString(
            '{% if settings.modules.sponsoring and session.can_manage_sponsoring %}',
            $template
        );
        $this->assertStringNotContainsString('{% if session.can_manage_sponsoring %}', $template);
    }

    public function testRolesTemplateGatesSponsoringPermissionUi(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        // Matrix-Zeile plus Checkboxen im Create- und Edit-Modal: drei Gates noetig.
        $gateCount = substr_count($template, '{% if settings.modules.sponsoring %}');
        $this->assertGreaterThanOrEqual(
            3,
            $gateCount,
            'Sponsoring permission UI (matrix row, create checkbox, edit checkbox) must be feature-gated.'
        );
    }

    public function testEnvExamplesAndProdComposeDocumentFeatureSponsoring(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_SPONSORING=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_SPONSORING=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_SPONSORING: ${FEATURE_SPONSORING:-false}', $compose);
    }
}
