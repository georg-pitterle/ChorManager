<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\Project;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class EventFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOldEventsHiddenByDefault(): void
    {
        $this->createEvent('Old Event', '-20 days');
        $this->createEvent('Recent Event', '-5 days');

        $body = $this->renderEventsIndex();

        $this->assertStringContainsString('Recent Event', $body);
        $this->assertStringNotContainsString('Old Event', $body);
    }

    public function testOldEventsShownWhenParameterActive(): void
    {
        $this->createEvent('Old Event', '-20 days');
        $this->createEvent('Recent Event', '-5 days');

        $defaultBody = $this->renderEventsIndex();

        $body = $this->renderEventsIndex(['show_old_events' => '1']);

        $this->assertStringContainsString('Recent Event', $defaultBody);
        $this->assertStringNotContainsString('Old Event', $defaultBody);
        $this->assertStringContainsString('Recent Event', $body);
        $this->assertStringContainsString('Old Event', $body);
    }

    public function testEventFrom14DaysAgoIsShown(): void
    {
        $this->createEvent('Event 14d Ago', '-14 days');
        $this->createEvent('Event 15d Ago', '-15 days');

        $defaultBody = $this->renderEventsIndex();
        $body = $this->renderEventsIndex(['show_old_events' => '1']);

        $this->assertStringContainsString('Event 14d Ago', $defaultBody);
        $this->assertStringNotContainsString('Event 15d Ago', $defaultBody);
        $this->assertStringContainsString('Event 14d Ago', $body);
        $this->assertStringContainsString('Event 15d Ago', $body);
    }

    public function testShowOldEventsCheckboxStatePersistedInUrl(): void
    {
        $body = $this->renderEventsIndex(['show_old_events' => '1']);

        $this->assertStringContainsString('id="show_old_events"', $body);
        $this->assertStringContainsString('name="show_old_events" value="1"', $body);
        $this->assertMatchesRegularExpression('/<input[^>]*id="show_old_events"[^>]*checked[^>]*>/', $body);
    }

    public function testOldEventsFilterWorksWithProjectFilter(): void
    {
        $project = Project::create([
            'name' => 'Event Feature Project',
            'description' => 'Project for event filtering tests',
        ]);

        $this->createEvent('Old Event in Project', '-20 days', $project->id);
        $this->createEvent('Recent Event in Project', '-5 days', $project->id);
        $this->createEvent('Old Event No Project', '-20 days');

        $defaultBody = $this->renderEventsIndex([
            'project_id' => (string) $project->id,
        ]);

        $body = $this->renderEventsIndex([
            'show_old_events' => '1',
            'project_id' => (string) $project->id,
        ]);

        $this->assertStringContainsString('Recent Event in Project', $defaultBody);
        $this->assertStringNotContainsString('Old Event in Project', $defaultBody);
        $this->assertStringNotContainsString('Old Event No Project', $defaultBody);
        $this->assertStringContainsString('Recent Event in Project', $body);
        $this->assertStringContainsString('Old Event in Project', $body);
        $this->assertStringNotContainsString('Old Event No Project', $body);
    }

    private function createEvent(string $title, string $relativeDate, ?int $projectId = null): Event
    {
        return Event::create([
            'title' => $title,
            'project_id' => $projectId,
            'event_date' => (new \DateTimeImmutable($relativeDate . ' 12:00:00'))->format('Y-m-d H:i:s'),
            'type' => 'Probe',
            'location' => 'Test Location',
        ]);
    }

    private function renderEventsIndex(array $queryParams = []): string
    {
        $_SERVER['REQUEST_URI'] = '/events' . ($queryParams === [] ? '' : '?' . http_build_query($queryParams));

        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/events');
        $environment->addGlobal('app_settings', []);
        $environment->addFunction(new TwigFunction(
            'nav_active',
            static function (
                string $path,
                ?string $activeNav = null,
                array $pathPrefixes = [],
                array $navKeys = [],
                array $excludePrefixes = []
            ): bool {
                foreach ($excludePrefixes as $excludePrefix) {
                    if ($excludePrefix !== '' && str_starts_with($path, $excludePrefix)) {
                        return false;
                    }
                }

                if ($activeNav !== null && $activeNav !== '' && in_array($activeNav, $navKeys, true)) {
                    return true;
                }

                foreach ($pathPrefixes as $prefix) {
                    if ($prefix === '/' && $path === '/') {
                        return true;
                    }

                    if ($prefix === '/') {
                        continue;
                    }

                    if ($prefix !== '' && str_starts_with($path, $prefix)) {
                        return true;
                    }
                }

                return false;
            }
        ));

        $controller = new EventController($twig);
        $request = $this->makeRequest('GET', '/events', [], $queryParams);
        $response = $this->makeResponse();

        $result = $controller->index($request, $response);

        return (string) $result->getBody();
    }
}
