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

        $nav = file_get_contents(dirname(__DIR__) . '/../src/Navigation/NavigationBuilder.php');
        $this->assertIsString($nav);

        // Slice out the '/registrations' entry itself so the gate assertion below can only
        // pass on its own visibility closure, not the '/evaluations/registrations' entry,
        // which reuses the identical "$c->module('registration')" token and even shares the
        // label 'Anmeldungen'.
        $registrationsEntry = $this->extractNavEntry($nav, '/registrations');
        $this->assertStringContainsString("\$c->module('registration')", $registrationsEntry);
    }

    /**
     * Slices the given NavigationBuilder source down to the single nav entry whose 'url' =>
     * matches exactly, from the nearest preceding 'label' => up to (but excluding) the next
     * entry's 'label' =>. Anchoring on 'url' rather than 'label' is deliberate: several nav
     * labels (Termine, Anmeldungen, Newsletter) are reused across two distinct entries, but
     * every entry's url is unique, so this cannot mis-slice into the wrong entry.
     */
    private function extractNavEntry(string $content, string $url): string
    {
        $urlNeedle = "'url' => '{$url}'";
        $urlPos = strpos($content, $urlNeedle);
        $this->assertNotFalse($urlPos, "Nav entry with url '{$url}' not found in NavigationBuilder.");

        $labelPos = strrpos(substr($content, 0, $urlPos), "'label' =>");
        $this->assertNotFalse($labelPos, "No preceding label found for nav entry '{$url}'.");

        $nextLabelPos = strpos($content, "'label' =>", $urlPos);
        $this->assertNotFalse($nextLabelPos, "No following nav entry found after '{$url}'.");

        return substr($content, $labelPos, $nextLabelPos - $labelPos);
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
