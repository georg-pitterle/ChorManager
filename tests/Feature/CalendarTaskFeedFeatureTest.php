<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\TaskController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\CalendarSubscriptionService;
use App\Services\EventAudienceService;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Policies\TaskPolicy;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Aufgaben im abonnierten Kalender.
 *
 * Ob sie überhaupt mitkommen und in welcher Gestalt, entscheidet jede Person im
 * eigenen Profil - eine Voreinstellung für alle gäbe es nicht: Wer den Kalender
 * als Terminplan liest, will die Aufgaben nicht dazwischen haben, wer ihn als
 * Tagesübersicht nutzt, gerade doch.
 */
final class CalendarTaskFeedFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const BASE_URL = 'https://chor.example';

    private CalendarFeedService $service;
    private Project $project;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->service = new CalendarFeedService(new NameFormatterService());

        $this->project = Project::create([
            'name' => 'Kalender-Aufgaben ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für den Aufgaben-Feed',
        ]);

        $this->user = $this->createUser('Feed', 'Abonnentin');
        $this->other = $this->createUser('Fremde', 'Person');
        $this->project->users()->attach([$this->user->id, $this->other->id]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCombinedFeedCarriesEventsAndTasks(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_COMBINED);
        $event = $this->createEventForUser($this->user);
        $task = $this->createTask('Bühne aufbauen', '+10 days', [$this->user->id]);

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        $this->assertStringContainsString('UID:event-' . $event->id . '@chor-manager', $ics);
        $this->assertStringContainsString('UID:task-' . $task->id . '@chor-manager', $ics);
        $this->assertStringContainsString('SUMMARY:Aufgabe: Bühne aufbauen', $ics);
    }

    public function testNoneKeepsTasksOutOfBothFeeds(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_NONE);
        $event = $this->createEventForUser($this->user);
        $task = $this->createTask('Unsichtbar', '+10 days', [$this->user->id]);

        $eventFeed = $this->service->buildEventCalendar($this->user, self::BASE_URL);
        $taskFeed = $this->service->buildTaskCalendar($this->user, self::BASE_URL);

        $this->assertStringContainsString('UID:event-' . $event->id . '@chor-manager', $eventFeed);
        $this->assertStringNotContainsString('task-' . $task->id . '@', $eventFeed);
        $this->assertStringNotContainsString('task-' . $task->id . '@', $taskFeed);
    }

    /**
     * Bei einem eigenen Abo darf die Aufgabe nicht zusätzlich im Termin-Feed
     * stehen - wer beide Adressen abonniert hat, sähe sie sonst doppelt.
     */
    public function testSeparateFeedHoldsTheTasksAndTheEventFeedStaysFree(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_SEPARATE);
        $this->createEventForUser($this->user);
        $task = $this->createTask('Programmheft', '+3 days', [$this->user->id]);

        $eventFeed = $this->service->buildEventCalendar($this->user, self::BASE_URL);
        $taskFeed = $this->service->buildTaskCalendar($this->user, self::BASE_URL);

        $this->assertStringNotContainsString('task-' . $task->id . '@', $eventFeed);
        $this->assertStringContainsString('UID:task-' . $task->id . '@chor-manager', $taskFeed);
    }

    public function testTheTaskFeedShowsOnlyOwnOpenTasksWithAnEndDate(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_SEPARATE);

        $own = $this->createTask('Eigene Aufgabe', '+5 days', [$this->user->id]);
        $shared = $this->createTask('Gemeinsame Aufgabe', '+6 days', [$this->user->id, $this->other->id]);
        $foreign = $this->createTask('Fremde Aufgabe', '+7 days', [$this->other->id]);
        $done = $this->createTask('Erledigte Aufgabe', '+8 days', [$this->user->id], 'Abgeschlossen');
        $undated = $this->createTask('Ohne Enddatum', null, [$this->user->id]);

        $ics = $this->service->buildTaskCalendar($this->user, self::BASE_URL);

        $this->assertStringContainsString('task-' . $own->id . '@', $ics);
        $this->assertStringContainsString('task-' . $shared->id . '@', $ics);
        $this->assertStringNotContainsString('task-' . $foreign->id . '@', $ics);
        $this->assertStringNotContainsString('task-' . $done->id . '@', $ics);
        $this->assertStringNotContainsString('task-' . $undated->id . '@', $ics);
    }

    /**
     * Ganztägig heißt in iCalendar: DTEND ist der erste Tag *danach*. Ohne den
     * zusätzlichen Tag zeigen Kalender einen Termin ohne Länge.
     */
    public function testEventFormatProducesAnAllDayEntryEndingTheNextDay(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_SEPARATE, User::CALENDAR_TASK_FORMAT_EVENT);
        $task = $this->createTask('Ganztägig', '+4 days', [$this->user->id]);

        $ics = $this->service->buildTaskCalendar($this->user, self::BASE_URL);
        $due = Carbon::parse((string) $task->end_date);

        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringNotContainsString('BEGIN:VTODO', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . $due->format('Ymd'), $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:' . $due->copy()->addDay()->format('Ymd'), $ics);
    }

    public function testTodoFormatProducesAVtodoWithADueDate(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_SEPARATE, User::CALENDAR_TASK_FORMAT_TODO);
        $task = $this->createTask('Als Aufgabe', '+4 days', [$this->user->id]);
        $task->update(['priority' => 'Hoch', 'status' => 'In Bearbeitung']);

        $ics = $this->service->buildTaskCalendar($this->user, self::BASE_URL);
        $due = Carbon::parse((string) $task->end_date);

        $this->assertStringContainsString('BEGIN:VTODO', $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('DUE;VALUE=DATE:' . $due->format('Ymd'), $ics);
        $this->assertStringContainsString('STATUS:IN-PROCESS', $ics);
        $this->assertStringContainsString('PRIORITY:1', $ics);
    }

    /**
     * Ein leerer Kalender statt eines Fehlers: Wer von "eigenes Abo" auf
     * "gemeinsam" wechselt, hat den Link meist noch im Kalenderprogramm stehen.
     */
    public function testTheTaskFeedStaysAValidCalendarWhenItIsEmpty(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_COMBINED);
        $this->createTask('Steht woanders', '+2 days', [$this->user->id]);

        $ics = $this->service->buildTaskCalendar($this->user, self::BASE_URL);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringNotContainsString('BEGIN:VTODO', $ics);
    }

    /**
     * Beide Feeds hängen bewusst am selben Token: Ein zweites Geheimnis mit
     * eigener Erneuerung hätte bedeutet, dass ein zurückgezogenes Abo nur die
     * eine Hälfte trifft.
     */
    public function testTheSameTokenOpensTheTaskFeed(): void
    {
        $this->setFeed(User::CALENDAR_TASK_FEED_SEPARATE);
        $task = $this->createTask('Über den Abo-Link', '+2 days', [$this->user->id]);
        $token = (new CalendarSubscriptionService())->rotateTokenForUser((int) $this->user->id);

        $response = $this->exportWithToken($token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/calendar', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('task-' . $task->id . '@', (string) $response->getBody());
    }

    public function testAnUnknownTokenIsNotFound(): void
    {
        $this->assertSame(404, $this->exportWithToken(str_repeat('a', 64))->getStatusCode());
    }

    private function exportWithToken(string $token): \Psr\Http\Message\ResponseInterface
    {
        $controller = new TaskController(
            $this->createStub(Twig::class),
            new HtmlSanitizer(),
            new TaskPolicy(),
            new NameFormatterService(),
            new NullLogger()
        );

        return $controller->exportCalendar(
            $this->makeRequest('GET', '/tasks/export/' . $token . '.ics'),
            $this->makeResponse(),
            ['token' => $token]
        );
    }

    private function setFeed(string $feed, string $format = User::CALENDAR_TASK_FORMAT_EVENT): void
    {
        $this->user->calendar_task_feed = $feed;
        $this->user->calendar_task_format = $format;
        $this->user->save();
    }

    /**
     * @param list<int> $assigneeIds
     */
    private function createTask(string $name, ?string $endOffset, array $assigneeIds, string $status = 'Offen'): Task
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => $status,
            'priority' => 'Mittel',
            'end_date' => $endOffset === null ? null : Carbon::now()->modify($endOffset)->toDateString(),
            'created_by' => $this->user->id,
        ]);
        $task->assignees()->sync($assigneeIds);

        return $task->fresh();
    }

    private function createEventForUser(User $audienceUser): Event
    {
        $event = Event::create([
            'title' => 'Probe ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
        ]);

        (new EventAudienceService())->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $audienceUser->id],
        ]);

        return $event->fresh();
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'email' => 'feed.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);
    }
}
