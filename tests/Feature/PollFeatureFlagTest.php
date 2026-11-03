<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\RolePermissionCatalog;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

/**
 * Das Modul Umfragen hängt an FEATURE_POLLS und ist von Haus aus aus.
 *
 * Ist es aus, gibt es keine Route, keinen Menüeintrag, keinen Anlass und kein
 * Recht in der Rollenverwaltung - geprüft hier und in den jeweiligen Tests.
 */
final class PollFeatureFlagTest extends TestCase
{
    public function testSettingsExposePollsFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'polls'\\s*=>\\s*EnvHelper::read\\('FEATURE_POLLS', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testEnvExamplesProdComposeAndReadmeDocumentFeaturePolls(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertStringContainsString('FEATURE_POLLS=', (string) file_get_contents($root . '/.env.example'));
        $this->assertStringContainsString('FEATURE_POLLS=', (string) file_get_contents($root . '/dist/.env.example'));
        $this->assertStringContainsString(
            'FEATURE_POLLS: ${FEATURE_POLLS:-false}',
            (string) file_get_contents($root . '/dist/docker-compose.prod.yml')
        );
        $this->assertStringContainsString('`FEATURE_POLLS=true`', (string) file_get_contents($root . '/README.md'));
    }

    public function testPollRoutesExistOnlyWithTheModule(): void
    {
        $this->assertSame([], $this->registeredPollRoutes(false));

        $enabled = $this->registeredPollRoutes(true);
        foreach (
            [
                'GET /polls',
                'GET /polls/{id:[0-9]+}',
                'POST /polls/{id:[0-9]+}/vote',
                'GET /polls/create',
                'POST /polls',
                'GET /polls/{id:[0-9]+}/edit',
                'POST /polls/{id:[0-9]+}',
                'POST /polls/{id:[0-9]+}/close',
                'POST /polls/{id:[0-9]+}/delete',
            ] as $route
        ) {
            $this->assertContains($route, $enabled);
        }
    }

    public function testPermissionBelongsToThePollsModule(): void
    {
        $this->assertSame('polls', RolePermissionCatalog::moduleGates()['can_manage_polls'] ?? null);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/roles/index.twig');
        $this->assertSame(2, substr_count($template, '{% if settings.modules.polls %}'));
    }

    /**
     * @return list<string>
     */
    private function registeredPollRoutes(bool $enabled): array
    {
        $settings = ['modules' => ['polls' => $enabled]];

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

        $found = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/polls')) {
                foreach ($route->getMethods() as $method) {
                    $found[] = $method . ' ' . $route->getPattern();
                }
            }
        }

        return array_values(array_unique($found));
    }
}
