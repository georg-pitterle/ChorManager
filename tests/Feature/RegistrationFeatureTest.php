<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

class RegistrationFeatureTest extends TestCase
{
    public function testRegistrationStructureExists(): void
    {
        $this->assertTrue(class_exists(RegistrationController::class));
        $this->assertTrue(method_exists(RegistrationController::class, 'index'));
        $this->assertTrue(method_exists(RegistrationController::class, 'detail'));

        $this->assertFileExists(dirname(__DIR__) . '/../templates/registrations/index.twig');
        $this->assertFileExists(dirname(__DIR__) . '/../templates/registrations/detail.twig');

        $nav = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/events.twig');
        $this->assertIsString($nav);
        $this->assertStringContainsString('settings.modules.registration', $nav);
        $this->assertStringContainsString('href="/registrations"', $nav);
    }

    public function testRegistrationRoutesRespectFeatureFlag(): void
    {
        $this->assertSame([], $this->registeredRegistrationRoutePatterns(false));

        $enabled = $this->registeredRegistrationRoutePatterns(true);
        $this->assertContains('/registrations', $enabled);
        $this->assertContains('/registrations/{event_id:[0-9]+}', $enabled);
    }

    /**
     * @return string[]
     */
    private function registeredRegistrationRoutePatterns(bool $enabled): array
    {
        $settings = ['modules' => ['registration' => $enabled]];

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
            if (str_starts_with($route->getPattern(), '/registrations')) {
                $patterns[] = $route->getPattern();
            }
        }

        return array_values(array_unique($patterns));
    }
}
