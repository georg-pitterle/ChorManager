<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Controllers\EventSeriesController;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\User;
use App\Policies\EventPolicy;
use App\Services\EntityCleanupService;
use App\Services\EventAudienceNotifier;
use App\Services\EventWriteService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Serie hat ihren eigenen Controller - Antwort auf Review-Lauf 77 (Frage 2b).
 *
 * Geprüft wird hier, was mit dem Umzug neu ist: dass die Route beim neuen
 * Controller liegt, dass der Container ihn baut, dass die Löschung von Terminen
 * und Notizen ganz oder gar nicht geschieht, und dass der alte Weg nicht
 * zurückkommt. Das Verhalten der Löschung selbst - Umfang ab dem angeklickten
 * Termin, Meldung, Rechteprüfung über die ganze Serie - hält weiterhin
 * EventSeriesScopeAndDeletionFeatureTest fest.
 */
final class EventSeriesControllerFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private EventSeries $series;
    private User $actor;
    /** @var list<Event> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->actor = User::create([
            'first_name' => 'Serien',
            'last_name' => 'Verwalter',
            'email' => 'serien.' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);

        $this->series = EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
        ]);

        $this->events = [];
        foreach ([7, 14] as $daysAhead) {
            $start = Carbon::now()->addDays($daysAhead)->setTime(19, 0);
            $this->events[] = Event::create([
                'title' => 'Serienprobe',
                'starts_at' => $start,
                'ends_at' => (clone $start)->setTime(21, 0),
                'series_id' => $this->series->id,
                'location' => 'Pfarrsaal',
            ]);
        }

        $_SESSION = ['user_id' => (int) $this->actor->id, 'can_manage_events' => true];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /** Die Route zeigt auf den neuen Controller, und nur im Terminverwaltungs-Block. */
    public function testTheRoutePointsAtTheSeriesController(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringContainsString(
            "\$eventGroup->post('/events/{id:[0-9]+}/delete-series', [EventSeriesController::class, 'delete']);",
            $routes
        );
        $this->assertStringNotContainsString("[EventController::class, 'deleteSeries']", $routes);
    }

    /** Der alte Weg kommt nicht zurück. */
    public function testTheEventControllerHasNoSeriesDeletionAnyMore(): void
    {
        $this->assertFalse(method_exists(EventController::class, 'deleteSeries'));
    }

    /** Im echten Container hängen alle Mitarbeiter am neuen Controller. */
    public function testTheContainerBuildsTheSeriesController(): void
    {
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/src/Settings.php')($builder);
        (require dirname(__DIR__, 2) . '/src/Dependencies.php')($builder);
        $container = $builder->build();
        $container->set(Capsule::class, Bootstrap::getCapsule());

        $controller = $container->get(EventSeriesController::class);

        $this->assertInstanceOf(
            EventPolicy::class,
            (new ReflectionProperty(EventSeriesController::class, 'policy'))->getValue($controller)
        );
        $this->assertInstanceOf(
            EventAudienceNotifier::class,
            (new ReflectionProperty(EventSeriesController::class, 'notifier'))->getValue($controller)
        );
        $this->assertInstanceOf(
            EventWriteService::class,
            (new ReflectionProperty(EventSeriesController::class, 'eventWriter'))->getValue($controller)
        );
    }

    /**
     * Termine und Notizen gehen zusammen oder gar nicht.
     *
     * Die Notizen hängen über entity_type/entity_id und tragen keinen
     * Fremdschlüssel; die Kaskade nimmt sie nicht mit. Scheitert ihr Abräumen,
     * dürfen die Termine nicht schon weg sein - sonst stünden Notizen ohne
     * Termin in der Tabelle, und niemand käme mehr an sie heran.
     */
    public function testNothingIsDeletedWhenPurgingTheNotesFails(): void
    {
        foreach ($this->events as $event) {
            Comment::create([
                'entity_type' => 'event',
                'entity_id' => (int) $event->id,
                'user_id' => (int) $this->actor->id,
                'comment' => 'Noten mitbringen',
                'is_private' => 0,
            ]);
        }

        $failingCleanup = new class extends EntityCleanupService {
            public function purgeForEntities(string $entityType, array $entityIds): array
            {
                throw new RuntimeException('Aufräumen nicht möglich');
            }
        };

        $controller = $this->eventSeriesController([
            'view' => $this->renderingStub(),
            'cleanup' => $failingCleanup,
        ]);

        try {
            $controller->delete(
                $this->makeRequest('POST', '/events/' . $this->events[0]->id . '/delete-series'),
                $this->makeResponse(),
                ['id' => (string) $this->events[0]->id]
            );
            self::fail('Das Abräumen der Notizen hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        $this->assertSame(
            2,
            Event::where('series_id', $this->series->id)->count(),
            'Kein Termin darf ohne das Abräumen seiner Notizen verschwinden.'
        );
        $this->assertSame(2, Comment::where('entity_type', 'event')->whereIn(
            'entity_id',
            array_map(static fn (Event $event): int => (int) $event->id, $this->events)
        )->count());
    }

    /** Der gute Weg: Termine und ihre Notizen sind weg, die Meldung nennt den Umfang. */
    public function testTheSeriesAndItsNotesAreGoneAfterwards(): void
    {
        Comment::create([
            'entity_type' => 'event',
            'entity_id' => (int) $this->events[1]->id,
            'user_id' => (int) $this->actor->id,
            'comment' => 'Zweite Probe',
            'is_private' => 0,
        ]);

        $response = $this->eventSeriesController(['view' => $this->renderingStub()])->delete(
            $this->makeRequest('POST', '/events/' . $this->events[0]->id . '/delete-series'),
            $this->makeResponse(),
            ['id' => (string) $this->events[0]->id]
        );

        $this->assertRedirect($response, '/events');
        $this->assertSame(0, Event::where('series_id', $this->series->id)->count());
        $this->assertSame(
            0,
            Comment::where('entity_type', 'event')
                ->where('entity_id', (int) $this->events[1]->id)
                ->count()
        );
        $this->assertStringContainsString('2 Termine', (string) ($_SESSION['success'] ?? ''));
    }

    /** Ohne Zielgruppen-Zugehörigkeit bleibt die Serie stehen. */
    public function testSomeoneOutsideTheAudienceDeletesNothing(): void
    {
        $_SESSION = ['user_id' => 999999, 'can_manage_events' => false];

        $response = $this->eventSeriesController(['view' => $this->renderingStub()])->delete(
            $this->makeRequest('POST', '/events/' . $this->events[0]->id . '/delete-series'),
            $this->makeResponse(),
            ['id' => (string) $this->events[0]->id]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(2, Event::where('series_id', $this->series->id)->count());
    }

    private function renderingStub(): Twig
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn (ResponseInterface $response): ResponseInterface => $response
        );

        return $twig;
    }
}
