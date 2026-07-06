<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class FinanceFeatureFlagTest extends TestCase
{
    private const ENV_KEYS = ['FEATURE_FINANCE', 'FEATURE_BUDGET'];

    /** @var array<string, array{env: string|null, server: string|null, getenv: string|false}> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $backup = $this->envBackup[$key];

            if ($backup['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $backup['env'];
            }

            if ($backup['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $backup['server'];
            }

            if ($backup['getenv'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $backup['getenv']);
            }
        }
    }

    public function testSettingsExposeFinanceFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/EnvHelper::read\\('FEATURE_FINANCE', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testBudgetModuleRequiresFinanceModule(): void
    {
        // Budget an, Finanzen aus -> Budget bleibt deaktiviert.
        $modules = $this->buildModules(['FEATURE_FINANCE' => 'false', 'FEATURE_BUDGET' => 'true']);
        $this->assertFalse($modules['finance']);
        $this->assertFalse($modules['budget']);
    }

    public function testBudgetModuleActiveWhenFinanceAndBudgetEnabled(): void
    {
        $modules = $this->buildModules(['FEATURE_FINANCE' => 'true', 'FEATURE_BUDGET' => 'true']);
        $this->assertTrue($modules['finance']);
        $this->assertTrue($modules['budget']);
    }

    public function testBudgetModuleStaysOffWithoutBudgetFlag(): void
    {
        $modules = $this->buildModules(['FEATURE_FINANCE' => 'true']);
        $this->assertTrue($modules['finance']);
        $this->assertFalse($modules['budget']);
    }

    public function testFinanceModuleDefaultsToOff(): void
    {
        $modules = $this->buildModules([]);
        $this->assertFalse($modules['finance']);
        $this->assertFalse($modules['budget']);
    }

    /**
     * Baut den echten DI-Container mit den Definitionen aus src/Settings.php und
     * liefert das modules-Array, wie es die Anwendung zur Laufzeit sehen wuerde.
     *
     * @param array<string, string> $env
     * @return array<string, bool>
     */
    private function buildModules(array $env): array
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        $builder = new ContainerBuilder();
        $defineSettings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $defineSettings($builder);

        return $builder->build()->get('settings')['modules'];
    }

    public function testFinanceRoutesAreRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['finance'] ?? false) {");
        $this->assertNotFalse($gatePos, 'Finance feature gate missing in Routes.php.');

        foreach (["'/finances'", "'/finances/report'", "'/finances/save'", "'/finances/settings'"] as $route) {
            $routePos = strpos($content, $route);
            $this->assertNotFalse($routePos, "Finance route $route missing in Routes.php.");
            $this->assertGreaterThan($gatePos, $routePos, "Finance route $route must be inside the feature gate.");
        }
    }

    public function testFinanceRouteRegistrationRespectsFeatureFlag(): void
    {
        $this->assertSame([], $this->registeredFinanceRoutePatterns(false));

        $enabledPatterns = $this->registeredFinanceRoutePatterns(true);
        $this->assertContains('/finances', $enabledPatterns);
        $this->assertContains('/finances/report', $enabledPatterns);
        $this->assertContains('/finances/save', $enabledPatterns);
        $this->assertContains('/finances/settings', $enabledPatterns);
    }

    /**
     * Minimale echte Slim-App wie in WebmailFeatureFlagTest/NewsletterFeatureFlagTest;
     * AppFactory::setContainer() wird von allen drei Tests selbst gesetzt.
     *
     * @return string[]
     */
    private function registeredFinanceRoutePatterns(bool $financeEnabled): array
    {
        $settings = ['modules' => ['finance' => $financeEnabled]];

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
            if (str_starts_with($route->getPattern(), '/finances')) {
                $patterns[] = $route->getPattern();
            }
        }

        return array_values(array_unique($patterns));
    }

    public function testNavigationGatesFinanceLinkBehindFeatureFlag(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/areas.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString(
            '{% set _finance_nav_perm = session.can_read_finances or session.can_manage_finances'
                . ' or session.can_manage_users %}',
            $template
        );
        $this->assertStringContainsString(
            '{% if settings.modules.finance and _finance_nav_perm %}',
            $template
        );
    }

    public function testDashboardTemplateGatesFinancePanel(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');
        $this->assertIsString($template);

        // Kassa-Panel und Empty-State teilen sich die Sichtbarkeitsbedingung.
        $this->assertStringContainsString('settings.modules.finance', $template);
        $this->assertStringNotContainsString(
            '{% if session.can_read_finances or session.can_manage_users %}',
            $template
        );
    }

    public function testRolesTemplateGatesFinancePermissionUi(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        // Matrix-Block plus Checkbox-Bloecke im Create- und Edit-Modal: drei Gates noetig.
        $gateCount = substr_count($template, '{% if settings.modules.finance %}');
        $this->assertGreaterThanOrEqual(
            3,
            $gateCount,
            'Finance permission UI (matrix rows, create checkboxes, edit checkboxes) must be feature-gated.'
        );
    }

    public function testEnvExamplesAndProdComposeDocumentFeatureFinance(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_FINANCE=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_FINANCE=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_FINANCE: ${FEATURE_FINANCE:-false}', $compose);
    }
}
