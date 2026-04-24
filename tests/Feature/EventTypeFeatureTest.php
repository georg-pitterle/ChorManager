<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventTypeController;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;

class EventTypeFeatureTest extends TestCase
{
    use TestHttpHelpers;

    public function testEventTypeStructureExists(): void
    {
        $this->assertTrue(class_exists(EventTypeController::class));
        $this->assertTrue(method_exists(EventTypeController::class, 'index'));
        $this->assertTrue(method_exists(EventTypeController::class, 'create'));
        $this->assertTrue(method_exists(EventTypeController::class, 'update'));
        $this->assertTrue(method_exists(EventTypeController::class, 'delete'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/event-types'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/settings/event_types.twig'));
    }

    public function testCreateValidationStoresOldFormValuesForModal(): void
    {
        $_SESSION = [];
        $controller = new EventTypeController($this->createTwig());

        $request = $this->makeRequest('POST', '/event-types', [
            'name' => '',
            'color' => 'danger',
        ]);
        $result = $controller->create($request, $this->makeResponse());

        $this->assertRedirect($result, '/event-types');
        $this->assertSame('Name ist ein Pflichtfeld.', $_SESSION['error'] ?? null);
        $this->assertSame('danger', $_SESSION['event_type_create_form']['color'] ?? null);
        $this->assertTrue((bool) ($_SESSION['event_type_create_open_modal'] ?? false));
    }

    public function testUpdateValidationStoresOldFormValuesForModal(): void
    {
        $_SESSION = [];
        $controller = new EventTypeController($this->createTwig());

        $request = $this->makeRequest('POST', '/event-types/42/update', [
            'name' => '',
            'color' => 'warning',
        ]);
        $result = $controller->update($request, $this->makeResponse(), ['id' => '42']);

        $this->assertRedirect($result, '/event-types');
        $this->assertSame('Name ist ein Pflichtfeld.', $_SESSION['error'] ?? null);
        $this->assertSame('warning', $_SESSION['event_type_edit_42_form']['color'] ?? null);
        $this->assertTrue((bool) ($_SESSION['event_type_edit_42_open_modal'] ?? false));
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->getEnvironment()->addGlobal('session', $_SESSION);
        $twig->getEnvironment()->addGlobal('current_path', '/event-types');
        $twig->getEnvironment()->addGlobal('app_settings', []);

        return $twig;
    }
}
