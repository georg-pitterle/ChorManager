<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\CalendarSubscriptionService;
use Carbon\Carbon;
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
        $_SESSION = [
            'user_id' => 1,
            'can_manage_users' => true,
        ];

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
        $this->assertMatchesRegularExpression('/name="show_old_events"\s+value="1"/', $body);
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

    public function testResetClearsShowOldEventsFilter(): void
    {
        $oldEvent = Event::create([
            'title' => 'Old Event',
            'starts_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 12:00:00',
            'ends_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 14:00:00',
            'type' => 'Probe',
            'location' => null,
        ]);

        $controller = new EventController($this->createTwig());

        $request = $this->makeRequest('GET', '/events?show_old_events=1', [], ['show_old_events' => '1']);
        $response = $this->makeResponse();
        $result = $controller->index($request, $response);

        $this->assertStringContainsString($oldEvent->title, (string) $result->getBody());

        $request = $this->makeRequest('GET', '/events');
        $response = $this->makeResponse();
        $result = $controller->index($request, $response);

        $this->assertStringNotContainsString($oldEvent->title, (string) $result->getBody());
    }

    public function testMultipleFiltersWorkTogether(): void
    {
        $project = Project::create([
            'name' => 'Project A',
            'description' => 'Project for combined event filter test',
        ]);
        $eventType = \App\Models\EventType::create([
            'name' => 'Probe',
            'color' => 'primary',
        ]);

        $oldEventInProject = Event::create([
            'title' => 'Old Event in Project',
            'starts_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 12:00:00',
            'ends_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 14:00:00',
            'project_id' => $project->id,
            'event_type_id' => $eventType->id,
            'type' => 'Probe',
            'location' => null,
        ]);

        $oldEventOtherProject = Event::create([
            'title' => 'Old Event Other Project',
            'starts_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 12:00:00',
            'ends_at' => Carbon::now()->subDays(20)->format('Y-m-d') . ' 14:00:00',
            'project_id' => null,
            'event_type_id' => $eventType->id,
            'type' => 'Probe',
            'location' => null,
        ]);

        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest(
            'GET',
            '/events?show_old_events=1&project_id=' . $project->id . '&event_type_id=' . $eventType->id,
            [],
            [
                'show_old_events' => '1',
                'project_id' => (string) $project->id,
                'event_type_id' => (string) $eventType->id,
            ]
        );
        $response = $this->makeResponse();
        $result = $controller->index($request, $response);

        $this->assertStringContainsString($oldEventInProject->title, (string) $result->getBody());
        $this->assertStringNotContainsString($oldEventOtherProject->title, (string) $result->getBody());
    }

    public function testNonAdminOnlySeesOwnProjectEventsAndGlobalEvents(): void
    {
        $memberProject = Project::create([
            'name' => 'Member Project',
            'description' => 'Own project',
        ]);
        $foreignProject = Project::create([
            'name' => 'Foreign Project',
            'description' => 'Other project',
        ]);

        $user = User::create([
            'first_name' => 'Event',
            'last_name' => 'Viewer',
            'email' => 'event.viewer@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        self::$capsule?->table('project_users')->insert([
            'project_id' => $memberProject->id,
            'user_id' => $user->id,
        ]);

        $ownEvent = $this->createEvent('Own Project Event', '-2 days', $memberProject->id);
        $foreignEvent = $this->createEvent('Foreign Project Event', '-2 days', $foreignProject->id);
        $globalEvent = $this->createEvent('Global Event', '-2 days');

        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['can_manage_users'] = false;

        $body = $this->renderEventsIndex();

        $this->assertStringContainsString($ownEvent->title, $body);
        $this->assertStringContainsString($globalEvent->title, $body);
        $this->assertStringNotContainsString($foreignEvent->title, $body);

        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EventController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString(
            '$hasUnauthorizedSeriesEvent = $eventsToUpdate->contains(function ($seriesEvent) {',
            $controllerContent
        );
        $this->assertStringContainsString(
            '$hasUnauthorizedSeriesEvent = $eventsToDelete->contains(function ($seriesEvent) {',
            $controllerContent
        );
    }

    public function testCreateEventRequiresAllTimeFields(): void
    {
        $controller = new EventController($this->createTwig());
        unset($_SESSION['error']);
        $request = $this->makeRequest('POST', '/events', [
            'title' => 'Missing Time',
            'starts_at' => '2026-05-01',
            // start_time and end_time intentionally omitted
        ]);
        $response = $this->makeResponse();
        $controller->create($request, $response);

        $this->assertEquals('Datum, Startzeit und Endzeit sind Pflichtfelder.', $_SESSION['error'] ?? null);
        $this->assertNull(Event::where('title', 'Missing Time')->first());
    }

    public function testCreateEventRejectsInvertedTimeRange(): void
    {
        $controller = new EventController($this->createTwig());
        unset($_SESSION['error']);
        $request = $this->makeRequest('POST', '/events', [
            'title' => 'Bad Times',
            'starts_at' => '2026-05-01',
            'start_time' => '21:00',
            'end_time'   => '19:00',
        ]);
        $response = $this->makeResponse();
        $controller->create($request, $response);

        $this->assertEquals('Endzeit muss nach der Startzeit liegen.', $_SESSION['error'] ?? null);
        $this->assertNull(Event::where('title', 'Bad Times')->first());
    }

    public function testCreateEventValidationErrorKeepsEnteredModalValues(): void
    {
        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest('POST', '/events', [
            'title' => 'Probe Dienstag',
            'starts_at' => '2026-06-10',
            'start_time' => '21:00',
            'end_time' => '19:00',
            'location' => 'Gemeindehaus',
            'repeat' => '1',
            'recurrence_interval' => '2',
            'frequency' => 'weekly',
            'weekdays' => ['2', '4'],
            'series_end_date' => '2026-09-01',
        ]);
        $response = $this->makeResponse();

        $result = $controller->create($request, $response);

        $this->assertRedirect($result, '/events');
        $this->assertSame('Endzeit muss nach der Startzeit liegen.', $_SESSION['error'] ?? null);

        $body = $this->renderEventsIndex();
        $this->assertStringContainsString('data-open-create-modal="1"', $body);
        $this->assertStringContainsString('value="Probe Dienstag"', $body);
        $this->assertStringContainsString('value="2026-06-10"', $body);
        $this->assertStringContainsString('value="21:00"', $body);
        $this->assertStringContainsString('value="19:00"', $body);
        $this->assertStringContainsString('value="Gemeindehaus"', $body);
        $this->assertStringContainsString('name="repeat"', $body);
        $this->assertStringContainsString('name="series_end_date"', $body);
        $this->assertStringContainsString('value="2026-09-01"', $body);
    }

    public function testCreateEventStoresTimeRange(): void
    {
        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest('POST', '/events', [
            'title' => 'Probe Montag',
            'starts_at' => '2026-05-01',
            'start_time' => '19:00',
            'end_time'   => '21:00',
        ]);
        $response = $this->makeResponse();
        $controller->create($request, $response);

        $event = Event::where('title', 'Probe Montag')->first();
        $this->assertNotNull($event);
        $this->assertEquals('2026-05-01 19:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-05-01 21:00:00', $event->ends_at->format('Y-m-d H:i:s'));
    }

    public function testUpdateEventStoresTimeRange(): void
    {
        $event = Event::create([
            'title' => 'Old Probe',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at'   => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest('POST', '/events/' . $event->id . '/update', [
            'title' => 'New Probe',
            'starts_at' => '2026-05-08',
            'start_time' => '18:00',
            'end_time'   => '20:00',
        ]);
        $response = $this->makeResponse();
        $controller->update($request, $response, ['id' => (string) $event->id]);

        $event->refresh();
        $this->assertEquals('2026-05-08 18:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-05-08 20:00:00', $event->ends_at->format('Y-m-d H:i:s'));
    }

    public function testUpdateEventValidationErrorKeepsEnteredFormValues(): void
    {
        $event = Event::create([
            'title' => 'Original Probe',
            'starts_at' => '2026-06-01 19:00:00',
            'ends_at' => '2026-06-01 21:00:00',
            'type' => 'Probe',
            'location' => 'Saal',
        ]);

        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest('POST', '/events/' . $event->id . '/update', [
            'title' => 'Neue Probe',
            'starts_at' => '2026-06-10',
            'start_time' => '20:30',
            'end_time' => '19:00',
            'location' => 'Aula',
            'update_series' => '1',
        ]);
        $response = $this->makeResponse();
        $result = $controller->update($request, $response, ['id' => (string) $event->id]);

        $this->assertRedirect($result, '/events/' . $event->id . '/edit');
        $this->assertSame('Endzeit muss nach der Startzeit liegen.', $_SESSION['error'] ?? null);

        $editResponse = $controller->edit(
            $this->makeRequest('GET', '/events/' . $event->id . '/edit'),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );
        $body = (string) $editResponse->getBody();

        $this->assertStringContainsString('Endzeit muss nach der Startzeit liegen.', $body);
        $this->assertStringContainsString('value="Neue Probe"', $body);
        $this->assertStringContainsString('value="2026-06-10"', $body);
        $this->assertStringContainsString('value="20:30"', $body);
        $this->assertStringContainsString('value="19:00"', $body);
        $this->assertStringContainsString('value="Aula"', $body);
    }

    public function testUpdateSeriesAppliesClockTimesToFutureEvents(): void
    {
        $series = \App\Models\EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => '2026-07-01',
        ]);

        $event1 = Event::create([
            'title' => 'Probe',
            'starts_at' => '2026-05-05 19:00:00',
            'ends_at'   => '2026-05-05 21:00:00',
            'series_id' => $series->id,
            'type' => 'Probe',
        ]);
        $event2 = Event::create([
            'title' => 'Probe',
            'starts_at' => '2026-05-12 19:00:00',
            'ends_at'   => '2026-05-12 21:00:00',
            'series_id' => $series->id,
            'type' => 'Probe',
        ]);
        $event3 = Event::create([
            'title' => 'Probe',
            'starts_at' => '2026-05-19 19:00:00',
            'ends_at'   => '2026-05-19 21:00:00',
            'series_id' => $series->id,
            'type' => 'Probe',
        ]);

        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest('POST', '/events/' . $event1->id . '/update', [
            'title' => 'Probe',
            'starts_at' => '2026-05-05',
            'start_time' => '18:30',
            'end_time'   => '20:30',
            'update_series' => '1',
        ]);
        $response = $this->makeResponse();
        $controller->update($request, $response, ['id' => (string) $event1->id]);

        $event1->refresh();
        $event2->refresh();
        $event3->refresh();

        $this->assertEquals('2026-05-05', $event1->starts_at->format('Y-m-d'));
        $this->assertEquals('18:30', $event1->starts_at->format('H:i'));
        $this->assertEquals('20:30', $event1->ends_at->format('H:i'));
        $this->assertEquals('2026-05-12', $event2->starts_at->format('Y-m-d'));
        $this->assertEquals('18:30', $event2->starts_at->format('H:i'));
        $this->assertEquals('20:30', $event2->ends_at->format('H:i'));
        $this->assertEquals('2026-05-19', $event3->starts_at->format('Y-m-d'));
        $this->assertEquals('18:30', $event3->starts_at->format('H:i'));
        $this->assertEquals('20:30', $event3->ends_at->format('H:i'));
    }

    public function testEventsIndexRendersTimeRange(): void
    {
        Event::create([
            'title' => 'Timed Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at'   => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $body = $this->renderEventsIndex(['show_old_events' => '1']);

        $this->assertStringContainsString('Timed Event', $body);
        $this->assertStringContainsString('19:00', $body);
        $this->assertStringContainsString('21:00', $body);
    }

    public function testEventDetailShowsPublicNotesToAllAndPrivateNotesOnlyToTheirCreator(): void
    {
        $event = Event::create([
            'title' => 'Event With Notes',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $creator = $this->createUser('creator');
        $otherUser = $this->createUser('other');

        Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $creator->id,
            'comment' => 'Öffentliche Bemerkung',
            'is_private' => false,
        ]);

        Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $creator->id,
            'comment' => 'Meine private Bemerkung',
            'is_private' => true,
        ]);

        Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $otherUser->id,
            'comment' => 'Fremde private Bemerkung',
            'is_private' => true,
        ]);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_users'] = false;

        $creatorIndexBody = $this->renderEventsIndex(['show_old_events' => '1']);
        $creatorBody = $this->renderEventDetail($event->id);

        $this->assertStringContainsString('data-label="Bemerkung"', $creatorIndexBody);
        $this->assertStringContainsString('data-has-note="1"', $creatorIndexBody);
        $this->assertStringNotContainsString('Öffentliche Bemerkung', $creatorIndexBody);
        $this->assertStringNotContainsString('Meine private Bemerkung', $creatorIndexBody);
        $this->assertStringNotContainsString('Fremde private Bemerkung', $creatorIndexBody);

        $this->assertStringContainsString('Öffentliche Bemerkung', $creatorBody);
        $this->assertStringContainsString('Meine private Bemerkung', $creatorBody);
        $this->assertStringNotContainsString('Fremde private Bemerkung', $creatorBody);
        $this->assertStringContainsString('name="is_private"', $creatorBody);
        $this->assertStringContainsString('/events/' . $event->id . '/notes/', $creatorBody);

        $_SESSION['user_id'] = (int) $otherUser->id;

        $otherBody = $this->renderEventDetail($event->id);

        $this->assertStringContainsString('Öffentliche Bemerkung', $otherBody);
        $this->assertStringContainsString('Fremde private Bemerkung', $otherBody);
        $this->assertStringNotContainsString('Meine private Bemerkung', $otherBody);
    }

    public function testEventDetailShowsEditButtonForEditors(): void
    {
        $event = Event::create([
            'title' => 'Editable Detail Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $_SESSION['can_manage_users'] = true;
        $_SESSION['role_level'] = 10;

        $body = $this->renderEventDetail($event->id);

        $this->assertStringContainsString('/events/' . $event->id . '/edit', $body);
        $this->assertStringContainsString('Termin bearbeiten', $body);
    }

    public function testEventDetailHidesEditButtonForNonEditors(): void
    {
        $event = Event::create([
            'title' => 'Non Editable Detail Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 10;

        $body = $this->renderEventDetail($event->id);

        $this->assertStringNotContainsString('/events/' . $event->id . '/edit', $body);
        $this->assertStringNotContainsString('Termin bearbeiten', $body);
    }

    public function testEventDetailHidesEditButtonForHighRoleLevelWithoutManageUsers(): void
    {
        // The edit route (/events/{id}/edit) is gated by RoleMiddleware on
        // can_manage_users only, not on role_level. A voice-group rep (role_level
        // 40) without can_manage_users must not see a control that 403s on click.
        $event = Event::create([
            'title' => 'High Level Non Editor Detail Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 40;

        $body = $this->renderEventDetail($event->id);

        $this->assertStringNotContainsString('/events/' . $event->id . '/edit', $body);
        $this->assertStringNotContainsString('Termin bearbeiten', $body);
    }

    public function testEventsIndexShowsEditControlForEditors(): void
    {
        $this->createEvent('Editable Index Event', '-1 days');

        $_SESSION['can_manage_users'] = true;
        $_SESSION['role_level'] = 10;

        $body = $this->renderEventsIndex(['show_old_events' => '1']);

        $this->assertStringContainsString('dropdown-toggle-split', $body);
        $this->assertStringContainsString('Termin bearbeiten', $body);
    }

    public function testEventsIndexHidesEditControlForHighRoleLevelWithoutManageUsers(): void
    {
        // Same route-gate mismatch as above, exercised on the list view's
        // split-button dropdown: role_level alone must never unlock it.
        $this->createEvent('High Level Non Editor Index Event', '-1 days');

        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 40;

        $body = $this->renderEventsIndex(['show_old_events' => '1']);

        $this->assertStringNotContainsString('dropdown-toggle-split', $body);
        $this->assertStringNotContainsString('Termin bearbeiten', $body);
    }

    public function testAddEventNoteStoresCreatorAndPrivacyFlag(): void
    {
        $event = Event::create([
            'title' => 'Event For New Note',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $user = $this->createUser('note-author');
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['can_manage_users'] = false;

        $controller = new EventController($this->createTwig());
        $request = $this->makeRequest('POST', '/events/' . $event->id . '/notes', [
            'content' => 'Neue private Bemerkung',
            'is_private' => '1',
        ]);
        $response = $this->makeResponse();

        $result = $controller->addNote($request, $response, ['id' => (string) $event->id]);

        $this->assertRedirect($result, '/events/' . $event->id);

        $note = Comment::where('entity_type', 'event')
            ->where('entity_id', $event->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame('Neue private Bemerkung', $note->comment);
        $this->assertTrue((bool) $note->is_private);
    }

    public function testPrivateEventNoteCanBeUpdatedAndDeletedByCreator(): void
    {
        $user = $this->createUser('private-owner');
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['can_manage_users'] = false;

        $event = Event::create([
            'title' => 'Event For Private Note',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $note = Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $user->id,
            'comment' => 'Alte private Bemerkung',
            'is_private' => true,
        ]);

        $controller = new EventController($this->createTwig());
        $updateRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/update', [
            'content' => 'Aktualisierte private Bemerkung',
        ]);
        $updateResponse = $this->makeResponse();

        $updateResult = $controller->updateNote(
            $updateRequest,
            $updateResponse,
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertRedirect($updateResult, '/events/' . $event->id);

        $note->refresh();
        $this->assertSame('Aktualisierte private Bemerkung', $note->comment);

        $deleteRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/delete');
        $deleteResponse = $this->makeResponse();

        $deleteResult = $controller->deleteNote(
            $deleteRequest,
            $deleteResponse,
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertRedirect($deleteResult, '/events/' . $event->id);
        $this->assertNull(Comment::find($note->id));
    }

    public function testPrivateEventNoteCannotBeUpdatedOrDeletedByOtherUsers(): void
    {
        $owner = $this->createUser('private-owner-2');
        $otherUser = $this->createUser('private-other-2');
        $event = Event::create([
            'title' => 'Protected Private Note Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $note = Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $owner->id,
            'comment' => 'Nicht anfassbar',
            'is_private' => true,
        ]);

        $_SESSION['user_id'] = (int) $otherUser->id;
        $_SESSION['can_manage_users'] = false;

        $controller = new EventController($this->createTwig());
        $updateRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/update', [
            'content' => 'Manipulationsversuch',
        ]);
        $updateResult = $controller->updateNote(
            $updateRequest,
            $this->makeResponse(),
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertSame(403, $updateResult->getStatusCode());

        $deleteRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/delete');
        $deleteResult = $controller->deleteNote(
            $deleteRequest,
            $this->makeResponse(),
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertSame(403, $deleteResult->getStatusCode());

        $note->refresh();
        $this->assertSame('Nicht anfassbar', $note->comment);
        $this->assertNotNull(Comment::find($note->id));
    }

    public function testPublicEventNoteCannotBeUpdatedOrDeleted(): void
    {
        $user = $this->createUser('public-owner');
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['can_manage_users'] = false;

        $event = Event::create([
            'title' => 'Public Note Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $note = Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $user->id,
            'comment' => 'Öffentlich und fix',
            'is_private' => false,
        ]);

        $controller = new EventController($this->createTwig());
        $updateRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/update', [
            'content' => 'Sollte nie gespeichert werden',
        ]);
        $updateResult = $controller->updateNote(
            $updateRequest,
            $this->makeResponse(),
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertSame(403, $updateResult->getStatusCode());

        $deleteRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/delete');
        $deleteResult = $controller->deleteNote(
            $deleteRequest,
            $this->makeResponse(),
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertSame(403, $deleteResult->getStatusCode());

        $note->refresh();
        $this->assertSame('Öffentlich und fix', $note->comment);
        $this->assertNotNull(Comment::find($note->id));
    }

    public function testPublicEventNoteCanBeUpdatedOrDeletedByEventEditors(): void
    {
        $author = $this->createUser('public-author');
        $editor = $this->createUser('public-editor');

        $event = Event::create([
            'title' => 'Editable Public Note Event',
            'starts_at' => '2026-05-01 19:00:00',
            'ends_at' => '2026-05-01 21:00:00',
            'type' => 'Probe',
        ]);

        $note = Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $author->id,
            'comment' => 'Öffentliche Notiz vom Autor',
            'is_private' => false,
        ]);

        $_SESSION['user_id'] = (int) $editor->id;
        $_SESSION['can_manage_users'] = true;

        $controller = new EventController($this->createTwig());
        $updateRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/update', [
            'content' => 'Von Bearbeiter aktualisiert',
        ]);

        $updateResult = $controller->updateNote(
            $updateRequest,
            $this->makeResponse(),
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertRedirect($updateResult, '/events/' . $event->id);

        $note->refresh();
        $this->assertSame('Von Bearbeiter aktualisiert', $note->comment);

        $deleteRequest = $this->makeRequest('POST', '/events/' . $event->id . '/notes/' . $note->id . '/delete');
        $deleteResult = $controller->deleteNote(
            $deleteRequest,
            $this->makeResponse(),
            ['id' => (string) $event->id, 'note_id' => (string) $note->id]
        );

        $this->assertRedirect($deleteResult, '/events/' . $event->id);
        $this->assertNull(Comment::find($note->id));
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/events');
        $environment->addGlobal('app_settings', []);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static function (string $path): string {
                return $path;
            }
        ));

        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/events', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }

    private function createUser(string $suffix): User
    {
        return User::create([
            'first_name' => 'Event',
            'last_name' => 'User ' . $suffix,
            'email' => 'event-' . $suffix . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    private function createEvent(string $title, string $relativeDate, ?int $projectId = null): Event
    {
        $date = (new \DateTimeImmutable($relativeDate . ' 12:00:00'))->format('Y-m-d');

        return Event::create([
            'title' => $title,
            'project_id' => $projectId,
            'starts_at' => $date . ' 12:00:00',
            'ends_at' => $date . ' 14:00:00',
            'type' => 'Probe',
            'location' => 'Test Location',
        ]);
    }

    private function renderEventsIndex(array $queryParams = []): string
    {
        $_SERVER['REQUEST_URI'] = '/events' . ($queryParams === [] ? '' : '?' . http_build_query($queryParams));

        $twig = $this->createTwig();

        $controller = new EventController($twig);
        $request = $this->makeRequest('GET', '/events', [], $queryParams);
        $response = $this->makeResponse();

        $result = $controller->index($request, $response);

        return (string) $result->getBody();
    }

    private function renderEventDetail(int $eventId): string
    {
        $_SERVER['REQUEST_URI'] = '/events/' . $eventId;

        $twig = $this->createTwig();
        $controller = new EventController($twig);
        $request = $this->makeRequest('GET', '/events/' . $eventId);
        $response = $this->makeResponse();

        $result = $controller->detail($request, $response, ['id' => (string) $eventId]);

        return (string) $result->getBody();
    }

    private function renderEventCalendarExport(string $token)
    {
        $twig = $this->createTwig();
        $controller = new EventController($twig);
        $request = $this->makeRequest('GET', '/events/export/' . $token . '.ics');
        $response = $this->makeResponse();

        return $controller->exportCalendar($request, $response, ['token' => $token]);
    }

    public function testCalendarViewReturns200(): void
    {
        $body = $this->renderEventsIndex(['view' => 'calendar']);
        $this->assertStringContainsString('id="event-calendar"', $body);
    }

    public function testCalendarViewContainsCalendarEventJson(): void
    {
        $this->createEvent('Kalenderprobe', '+3 days');
        $body = $this->renderEventsIndex(['view' => 'calendar']);

        $this->assertStringContainsString('data-calendar-events', $body);

        preg_match('/data-calendar-events="([^"]*)"/', $body, $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'data-calendar-events attribute not found or empty');

        $events = json_decode(html_entity_decode($matches[1]), true);
        $this->assertIsArray($events);
        $this->assertNotEmpty($events);
        $first = $events[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('start', $first);
        $this->assertArrayHasKey('end', $first);

        $titles = array_column($events, 'title');
        $this->assertContains('Kalenderprobe', $titles, 'Created event title not found in calendar events JSON');
        $probe = $events[array_search('Kalenderprobe', $titles)];
        $this->assertArrayHasKey('id', $probe);
        $this->assertArrayHasKey('start', $probe);
        $this->assertArrayHasKey('end', $probe);
    }

    public function testEventDetailShowsCalendarSubscriptionButton(): void
    {
        $event = $this->createEvent('Probe-Termin', '+3 days');

        $body = $this->renderEventDetail($event->id);

        $this->assertStringContainsString('Kalender abonnieren', $body);
        $this->assertStringContainsString('id="calendarSubscriptionModal"', $body);
        $this->assertStringContainsString('id="calendarSubscriptionUrlInput"', $body);
    }

    public function testPersonalCalendarExportReturnsIcsForValidToken(): void
    {
        $this->createEvent('Probe-Termin', '+3 days');

        $token = (new CalendarSubscriptionService())->getOrCreateTokenForUser(1);
        $response = $this->renderEventCalendarExport($token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/calendar; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('SUMMARY:Probe-Termin', $body);
        $this->assertStringContainsString('DTSTART;TZID=', $body);
    }

    public function testPersonalCalendarExportReturns404ForInvalidToken(): void
    {
        $response = $this->renderEventCalendarExport(str_repeat('a', 64));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListViewShowsTable(): void
    {
        $body = $this->renderEventsIndex(['view' => 'list']);
        $this->assertStringContainsString('id="eventsTable"', $body);
        $this->assertStringNotContainsString('id="event-calendar"', $body);
    }

    public function testInvalidViewParameterFallsBackToList(): void
    {
        $body = $this->renderEventsIndex(['view' => 'foobar']);
        $this->assertStringContainsString('id="eventsTable"', $body);
        $this->assertStringNotContainsString('id="event-calendar"', $body);
    }

    public function testNonAdminDoesNotSeeCalendarAdminMarker(): void
    {
        $_SESSION['can_manage_users'] = false;
        $body = $this->renderEventsIndex(['view' => 'calendar']);
        $this->assertStringNotContainsString('data-calendar-admin', $body);
    }
}
