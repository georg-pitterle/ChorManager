<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventTypeController;
use PHPUnit\Framework\TestCase;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

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

    /**
     * Ein Fehler ohne Modal-Fenster - das Löschen hat keines - braucht einen
     * Platz über der Liste.
     *
     * index() nimmt `error` aus der Sitzung und gibt es ans Template weiter.
     * Dort stand es nur in `show_modal_error()`, und die Macro schweigt, wenn
     * kein Modal-Zustand daneben liegt. Die Meldung war damit zweifach verloren:
     * im Template ungenutzt und aus der Sitzung schon entfernt, sodass auch das
     * Auffangnetz im Layout (partials/flash.twig) nichts mehr fand. Wer eine
     * belegte Terminart löschte, sah die unveränderte Liste ohne Begründung.
     */
    public function testPageLevelErrorWithoutModalStateIsRendered(): void
    {
        $_SESSION = [];
        $_SESSION['error'] = 'Die Terminart konnte nicht gelöscht werden.';

        $controller = new EventTypeController($this->createTwig());
        $result = $controller->index($this->makeRequest('GET', '/event-types'), $this->makeResponse());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString(
            'Die Terminart konnte nicht gelöscht werden.',
            (string) $result->getBody()
        );
    }

    /**
     * Die Gegenprobe: Gehört der Fehler zu einem Modal, bleibt er dort und
     * erscheint nicht zusätzlich über der Liste.
     */
    public function testModalErrorIsNotRepeatedAboveTheList(): void
    {
        $_SESSION = [];
        $_SESSION['error'] = 'Name ist ein Pflichtfeld.';
        $_SESSION['event_type_create_error'] = 'event_type_create';
        $_SESSION['event_type_create_open_modal'] = true;

        $controller = new EventTypeController($this->createTwig());
        $result = $controller->index($this->makeRequest('GET', '/event-types'), $this->makeResponse());

        $body = (string) $result->getBody();
        $this->assertSame(1, substr_count($body, 'Name ist ein Pflichtfeld.'));
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/event-types');
        $environment->addGlobal('app_settings', []);

        // Die Prüfungen unten rendern die ganze Seite, damit auch das
        // Auffangnetz im Layout (partials/flash.twig) mitläuft. Dafür braucht
        // das Layout die Handvoll Funktionen, die ihm die Anwendung sonst
        // mitgibt. Ohne `flash` bleibt partials/flash.twig leer - genau der
        // Fall, den der Kommentar dort beschreibt, und genau der Grund, warum
        // eine aus der Sitzung entnommene Meldung von der Seite selbst
        // angezeigt werden muss.
        $settings = ['modules' => []];
        $environment->addGlobal('settings', $settings);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static fn (string $path): string => $path
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = '') use ($settings): array {
                $context = NavigationContext::fromSession($_SESSION, $settings, '/event-types', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }
}
