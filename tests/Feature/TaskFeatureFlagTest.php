<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class TaskFeatureFlagTest extends TestCase
{
    public function testSettingsExposeTasksFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'tasks'\\s*=>\\s*EnvHelper::read\\('FEATURE_TASKS', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testTaskRoutesAreRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['tasks'] ?? false) {");
        $this->assertNotFalse($gatePos, 'Tasks feature gate missing in Routes.php.');

        foreach (["'/{project_id:[0-9]+}/tasks'", "'/tasks'"] as $route) {
            $routePos = strpos($content, $route);
            $this->assertNotFalse($routePos, "Task route $route missing in Routes.php.");
            $this->assertGreaterThan($gatePos, $routePos, "Task route $route must be inside the feature gate.");
        }
    }

    public function testTaskRouteRegistrationRespectsFeatureFlag(): void
    {
        $this->assertSame([], $this->registeredTaskRoutePatterns(false));

        $enabledPatterns = $this->registeredTaskRoutePatterns(true);
        $this->assertContains('/projects/{project_id:[0-9]+}/tasks', $enabledPatterns);
        $this->assertContains('/tasks/{id:[0-9]+}', $enabledPatterns);
        $this->assertContains('/tasks/{id:[0-9]+}/status', $enabledPatterns);
    }

    /**
     * Minimale echte Slim-App wie in den anderen FeatureFlag-Tests;
     * AppFactory::setContainer() wird von allen diesen Tests selbst gesetzt.
     *
     * @return string[]
     */
    private function registeredTaskRoutePatterns(bool $tasksEnabled): array
    {
        $settings = ['modules' => ['tasks' => $tasksEnabled]];

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
            $pattern = $route->getPattern();
            if (str_starts_with($pattern, '/tasks') || str_contains($pattern, '}/tasks')) {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    public function testDashboardTemplateGatesTaskSectionBehindFeatureFlag(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString(
            '{% if settings.modules.tasks and session.can_manage_tasks %}',
            $template
        );
    }

    public function testProjectsIndexGatesTaskLinkBehindFeatureFlag(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/projects/index.twig');
        $this->assertIsString($template);

        $this->assertStringContainsString(
            '{% if settings.modules.tasks and session.can_manage_tasks %}',
            $template
        );
        $this->assertStringNotContainsString('{% if session.can_manage_tasks %}', $template);
    }

    public function testRolesTemplateGatesTaskPermissionUi(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        // Matrix-Zeile plus Checkboxen im Create- und Edit-Modal: drei Gates noetig.
        $gateCount = substr_count($template, '{% if settings.modules.tasks %}');
        $this->assertGreaterThanOrEqual(
            3,
            $gateCount,
            'Task permission UI (matrix row, create checkbox, edit checkbox) must be feature-gated.'
        );
    }

    public function testEnvExamplesAndProdComposeDocumentFeatureTasks(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_TASKS=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_TASKS=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_TASKS: ${FEATURE_TASKS:-false}', $compose);
    }
}
