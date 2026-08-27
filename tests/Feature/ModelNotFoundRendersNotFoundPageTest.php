<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Unit\Bootstrap;

/**
 * Ein nicht (mehr) vorhandener Datensatz ist ein 404, kein Serverfehler.
 *
 * `findOrFail()` wirft eine ModelNotFoundException, sobald ein Eintrag fehlt - ein
 * veraltetes Lesezeichen auf /tasks/26 oder ein Link auf einen inzwischen
 * gelöschten Eintrag genügt. Ohne eigene Behandlung wurde daraus ein
 * "Slim Application Error": HTTP 500 für die aufrufende Person und ein
 * Fehlerprotokoll-Eintrag mit vollem Stapelverlauf für etwas völlig Normales.
 *
 * Beobachtet in Produktion: GET /tasks/26,
 * "No query results for model [App\Models\Task] 26" aus TaskController::detail().
 *
 * Hinweis: AppFactory::setContainer() verändert prozessweiten statischen Zustand
 * von Slim; jeder Aufbau hier setzt deshalb seinen eigenen Container.
 */
final class ModelNotFoundRendersNotFoundPageTest extends TestCase
{
    private const ENV_KEY = 'APP_ENV';

    private ?string $originalEnv = null;
    private bool $hadEnv = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->hadEnv = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnv = $_ENV[self::ENV_KEY] ?? null;

        // Ohne Fehlerdetails - so läuft die Anwendung in Produktion, und nur dann
        // ist die gerenderte Seite überhaupt die Frage.
        $this->putEnvValue('production');
    }

    protected function tearDown(): void
    {
        if ($this->hadEnv) {
            $this->putEnvValue((string) $this->originalEnv);
        } else {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
            putenv(self::ENV_KEY);
        }

        parent::tearDown();
    }

    private function putEnvValue(string $value): void
    {
        $_ENV[self::ENV_KEY] = $value;
        $_SERVER[self::ENV_KEY] = $value;
        putenv(self::ENV_KEY . '=' . $value);
    }

    /**
     * Baut die echte Anwendung samt src/Middleware.php.
     */
    private function buildApp(): App
    {
        $containerBuilder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($containerBuilder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($containerBuilder);
        $container = $containerBuilder->build();
        $container->get(Capsule::class);

        AppFactory::setContainer($container);

        return AppFactory::create();
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    public function testMissingModelRendersTheNotFoundPageInsteadOfAServerError(): void
    {
        $app = $this->buildApp();

        // Nicht statisch: Slim bindet Route-Closures an den Container, und
        // Closure::bind() liefert für eine statische Closure null zurück.
        $app->get(
            '/tasks/{id}',
            function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
                throw (new ModelNotFoundException())->setModel('App\Models\Task', [26]);
            }
        );
        (require dirname(__DIR__, 2) . '/src/Middleware.php')($app);

        $response = $this->get($app, '/tasks/26');

        $this->assertSame(404, $response->getStatusCode());

        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('Slim Application Error', $body);
        $this->assertStringNotContainsString('ModelNotFoundException', $body);
    }

    /**
     * Die bestehende Behandlung unbekannter Routen darf dabei nicht verloren gehen -
     * beide Fälle teilen sich jetzt denselben Handler.
     */
    public function testUnknownRouteStillRendersTheNotFoundPage(): void
    {
        $app = $this->buildApp();
        (require dirname(__DIR__, 2) . '/src/Middleware.php')($app);

        $response = $this->get($app, '/gibt-es-nicht');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('Slim Application Error', (string) $response->getBody());
    }

    /**
     * Andere Fehler bleiben Serverfehler - der Handler darf nicht jede Ausnahme
     * stillschweigend zu einem 404 machen.
     */
    public function testUnrelatedErrorsStillSurfaceAsServerErrors(): void
    {
        $app = $this->buildApp();
        $app->get(
            '/kaputt',
            function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
                throw new \RuntimeException('Etwas ganz anderes ist schiefgelaufen');
            }
        );
        (require dirname(__DIR__, 2) . '/src/Middleware.php')($app);

        $this->assertSame(500, $this->get($app, '/kaputt')->getStatusCode());
    }
}
