<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Controllers\EventTypeController;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Die Terminseite als Terminplan: Mitglieder sehen einen schlanken Plan nach
 * Monaten, wer Termine verwaltet, zusätzlich die Tabelle mit Werkzeugen.
 * Die Uhr steht fest auf Montag, 12.10.2026, 10 Uhr, damit Wochentage und
 * relative Angaben prüfbar sind.
 */
class EventScheduleFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;

    private static ?Capsule $capsule = null;
    private User $sessionUser;

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

        self::$capsule?->connection()->beginTransaction();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));

        $this->sessionUser = User::create([
            'email' => 'schedule_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Paula',
            'last_name' => 'Planlein',
            'is_active' => 1,
        ]);

        $this->actAsMember();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testMemberSeesThePlanAndNoTableTools(): void
    {
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');

        $body = $this->render();

        $this->assertStringContainsString('data-event-plan', $body);
        $this->assertStringNotContainsString('data-table-engine', $body);
        $this->assertStringNotContainsString('href="/events?view=list', $body);
    }

    public function testMemberAskingForTheTableStillGetsThePlan(): void
    {
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');

        $body = $this->render(['view' => 'list']);

        $this->assertStringContainsString('data-event-plan', $body);
        $this->assertStringNotContainsString('data-table-engine', $body);
    }

    public function testManagerStartsInTheTableAndCanSwitchToThePlan(): void
    {
        $this->actAsManager();
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');

        $listBody = $this->render();
        $planBody = $this->render(['view' => 'plan']);

        $this->assertStringContainsString('data-table-engine', $listBody);
        $this->assertStringContainsString('href="/events?view=plan', $listBody);
        $this->assertStringContainsString('data-event-plan', $planBody);
    }

    public function testPlanGroupsByMonthAndShowsWeekdays(): void
    {
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');
        $this->createEvent('Novemberprobe', '2026-11-05 19:00');

        $body = $this->render();

        $this->assertStringContainsString('Oktober 2026', $body);
        $this->assertStringContainsString('November 2026', $body);
        $this->assertMatchesRegularExpression('/>\s*Di\s*</', $body);
        $this->assertMatchesRegularExpression('/>\s*Do\s*</', $body);
    }

    public function testNextEventIsHighlightedWithARelativeDate(): void
    {
        $this->createEvent('Gestrige Probe', '2026-10-11 19:00');
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');
        $this->createEvent('Spätere Probe', '2026-10-20 19:00');

        $body = $this->render();

        $this->assertMatchesRegularExpression('/data-next-event[^>]*>.*?Dienstagsprobe/s', $body);
        $this->assertStringContainsString('morgen', $body);
    }

    public function testTableShowsWeekdayAndRelativeDateToo(): void
    {
        $this->actAsManager();
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');

        $body = $this->render(['view' => 'list']);

        $this->assertMatchesRegularExpression('/Di\s+13\.10\.2026/', $body);
        $this->assertStringContainsString('morgen', $body);
        $this->assertStringContainsString('data-sort-starts_at="2026-10-13 19:00"', $body);
    }

    public function testTitleLinksToTheDetailPageAndTheNoteColumnIsGone(): void
    {
        $this->actAsManager();
        $event = $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');
        Comment::create([
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'user_id' => $this->sessionUser->id,
            'comment' => 'Bitte Noten mitbringen',
            'is_private' => 0,
        ]);

        $body = $this->render(['view' => 'list']);

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="\/events\/' . $event->id . '"[^>]*>\s*Dienstagsprobe/',
            $body
        );
        $this->assertStringNotContainsString('>Bemerkung<', $body);
        $this->assertStringNotContainsString('Bemerkungen (', $body);
        // Bemerkungen sind ein eigener, sichtbarer Hinweis mit Wort und führen direkt zu ihnen.
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="\/events\/' . $event->id . '#notes"[^>]*class="event-note-badge"[^>]*>(?:(?!<\/a>).)*1 Bemerkung\s*</s',
            $body
        );
    }

    public function testHighlightedEventTypeMarksItsEvents(): void
    {
        $this->actAsManager();
        $concert = EventType::create(['name' => 'Konzert', 'color' => 'danger', 'is_highlighted' => 1]);
        $this->createEvent('Herbstkonzert', '2026-10-17 18:00', (int) $concert->id);
        $this->createEvent('Dienstagsprobe', '2026-10-13 19:00');

        $listBody = $this->render(['view' => 'list']);
        $planBody = $this->render(['view' => 'plan']);

        $this->assertMatchesRegularExpression('/<tr[^>]*class="[^"]*is-highlighted[^"]*"[^>]*>(?:(?!<\/tr>).)*Herbstkonzert/s', $listBody);
        $this->assertMatchesRegularExpression('/<li[^>]*class="[^"]*is-highlighted[^"]*"[^>]*>(?:(?!<\/li>).)*Herbstkonzert/s', $planBody);
        $this->assertDoesNotMatchRegularExpression('/<tr[^>]*is-highlighted[^>]*>(?:(?!<\/tr>).)*Dienstagsprobe/s', $listBody);
    }

    public function testEmptyStateDistinguishesFilterFromNoEvents(): void
    {
        $type = EventType::create(['name' => 'Leertyp', 'color' => 'info']);

        $unfiltered = $this->render();
        $filtered = $this->render(['event_type_id' => (string) $type->id]);

        $this->assertStringContainsString('Keine Termine geplant', $unfiltered);
        $this->assertStringContainsString('Kein Termin passt zur Auswahl', $filtered);
        $this->assertStringContainsString('Auswahl zurücksetzen', $filtered);
    }

    public function testPageSpeaksOfTermineNotEvents(): void
    {
        $this->actAsManager();

        $body = $this->render(['view' => 'list']);

        $this->assertStringNotContainsString('Event-Liste', $body);
        $this->assertStringNotContainsString('Event-Typ', $body);
        $this->assertStringNotContainsString('Events (Termine)', $body);
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Termine\s*<\/h1>/', $body);
    }

    public function testFiltersSitInTheTableToolbarWithoutASeparateResetButton(): void
    {
        $this->actAsManager();

        $body = $this->render(['view' => 'list']);

        $this->assertMatchesRegularExpression('/data-table-plugin-slot[^>]*>\s*<form[^>]*id="event-filter-form"/', $body);
        $this->assertStringNotContainsString('id="events-filter-title"', $body);
        $this->assertStringNotContainsString('bi-x-circle"></i> Filter zurücksetzen', $body);
    }

    public function testCreateModalNamesTheNotificationAndHidesUnusedFields(): void
    {
        $this->actAsManager();

        $body = $this->render(['view' => 'list']);

        $this->assertStringNotContainsString('style="display:none', $body);
        $this->assertMatchesRegularExpression('/<div[^>]*id="recurrence_options"[^>]*hidden/', $body);
        $this->assertStringContainsString('Speichern und benachrichtigen', $body);
        $this->assertStringContainsString('data-notify-label="Speichern und benachrichtigen"', $body);
    }

    public function testEventTypeSettingsStoreTheHighlightFlag(): void
    {
        $_SESSION['can_manage_master_data'] = true;
        $controller = new EventTypeController($this->createAppTwig('/event-types'));

        $controller->create(
            $this->makeRequest('POST', '/event-types', ['name' => 'Galakonzert', 'color' => 'danger', 'is_highlighted' => '1']),
            $this->makeResponse()
        );
        $controller->create(
            $this->makeRequest('POST', '/event-types', ['name' => 'Stimmprobe', 'color' => 'info']),
            $this->makeResponse()
        );

        $this->assertTrue((bool) EventType::where('name', 'Galakonzert')->value('is_highlighted'));
        $this->assertFalse((bool) EventType::where('name', 'Stimmprobe')->value('is_highlighted'));

        $type = EventType::where('name', 'Galakonzert')->firstOrFail();
        $controller->update(
            $this->makeRequest('POST', '/event-types/' . $type->id . '/update', ['name' => 'Galakonzert', 'color' => 'danger']),
            $this->makeResponse(),
            ['id' => (string) $type->id]
        );

        $this->assertFalse((bool) $type->fresh()->is_highlighted);
    }

    public function testChosenViewIsRememberedInACookie(): void
    {
        $response = $this->renderResponse(['view' => 'calendar']);

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('events_view=calendar;', $cookie);
        $this->assertStringContainsString('Path=/events', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertMatchesRegularExpression('/Max-Age=\d+/', $cookie);
        $this->assertStringNotContainsString('Secure', $cookie);
    }

    public function testCookieIsSecureOverHttps(): void
    {
        $response = $this->renderResponse(['view' => 'plan'], [], ['HTTPS' => 'on']);

        $this->assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testRememberedViewOpensWhenNoViewIsRequested(): void
    {
        $this->actAsManager();

        $body = $this->render([], ['events_view' => 'plan']);

        $this->assertStringContainsString('data-event-plan', $body);
        $this->assertStringNotContainsString('data-table-engine', $body);
    }

    public function testExplicitViewWinsOverTheRememberedOne(): void
    {
        $body = $this->render(['view' => 'plan'], ['events_view' => 'calendar']);

        $this->assertStringContainsString('data-event-plan', $body);
        $this->assertStringNotContainsString('id="event-calendar"', $body);
    }

    public function testRememberedTableIsNotHandedToAMember(): void
    {
        // Ein Gerät, auf dem vorher jemand mit Verwaltungsrecht angemeldet war.
        $response = $this->renderResponse([], ['events_view' => 'list']);
        $body = (string) $response->getBody();

        $this->assertStringContainsString('data-event-plan', $body);
        $this->assertStringNotContainsString('data-table-engine', $body);
        $this->assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testUnknownViewIsNeitherUsedNorRemembered(): void
    {
        $this->actAsManager();

        $fromCookie = $this->renderResponse([], ['events_view' => '<script>']);
        $fromQuery = $this->renderResponse(['view' => '<script>']);

        $this->assertStringContainsString('data-table-engine', (string) $fromCookie->getBody());
        $this->assertSame('', $fromCookie->getHeaderLine('Set-Cookie'));
        $this->assertSame('', $fromQuery->getHeaderLine('Set-Cookie'));
    }

    private function actAsMember(): void
    {
        $_SESSION = ['user_id' => (int) $this->sessionUser->id];
    }

    private function actAsManager(): void
    {
        $_SESSION = [
            'user_id' => (int) $this->sessionUser->id,
            'can_manage_events' => true,
        ];
    }

    private function createEvent(string $title, string $startsAt, ?int $eventTypeId = null): Event
    {
        $start = Carbon::parse($startsAt);
        $event = Event::create([
            'title' => $title,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'event_type_id' => $eventTypeId,
            'type' => 'Probe',
            'location' => 'Pfarrsaal',
        ]);

        return $this->openToEveryone($event);
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, string> $cookies
     */
    private function render(array $queryParams = [], array $cookies = []): string
    {
        return (string) $this->renderResponse($queryParams, $cookies)->getBody();
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, string> $cookies
     * @param array<string, string> $serverParams
     */
    private function renderResponse(
        array $queryParams = [],
        array $cookies = [],
        array $serverParams = []
    ): ResponseInterface {
        $_SERVER['REQUEST_URI'] = '/events' . ($queryParams === [] ? '' : '?' . http_build_query($queryParams));

        $controller = new EventController(
            $this->createAppTwig('/events'),
            new NameFormatterService(),
            new NullLogger(),
            new ProjectQuery(new NameFormatterService())
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/events', $serverParams)
            ->withQueryParams($queryParams)
            ->withCookieParams($cookies);

        return $controller->index($request, $this->makeResponse());
    }
}
