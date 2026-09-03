<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Kalender-Abo trägt auch die vergangenen Termine.
 *
 * Ein Kalenderprogramm lebt von der Rückschau: Wer im Abo zurückblättert, will
 * die Proben des letzten Halbjahres sehen. Vorher endete der Feed am heutigen
 * Tag, davor stand im Kalender nichts.
 */
final class CalendarFeedPastEventsFeatureTest extends TestCase
{
    private const BASE_URL = 'https://chor.example';

    private CalendarFeedService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->service = new CalendarFeedService(new NameFormatterService());
        $this->user = User::create([
            'email' => 'kalender.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'Rita',
            'last_name' => 'Rückblick',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePastIsPartOfTheSubscription(): void
    {
        $lastWeek = $this->createEvent('Probe letzte Woche', -7);
        $lastYear = $this->createEvent('Konzert im Vorjahr', -400);
        $nextWeek = $this->createEvent('Probe nächste Woche', 7);

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        $this->assertStringContainsString('UID:event-' . $lastWeek->id . '@', $ics);
        $this->assertStringContainsString('UID:event-' . $lastYear->id . '@', $ics);
        $this->assertStringContainsString('UID:event-' . $nextWeek->id . '@', $ics);
    }

    /**
     * Sortiert bleibt nach Beginn, damit der Feed in der Reihenfolge steht, in
     * der die Termine stattgefunden haben beziehungsweise stattfinden werden.
     */
    public function testTheFeedStaysOrderedByStart(): void
    {
        $middle = $this->createEvent('Mitte', -3);
        $oldest = $this->createEvent('Ältester', -30);
        $newest = $this->createEvent('Jüngster', 30);

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        $positions = [
            (int) strpos($ics, 'event-' . $oldest->id . '@'),
            (int) strpos($ics, 'event-' . $middle->id . '@'),
            (int) strpos($ics, 'event-' . $newest->id . '@'),
        ];

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    /**
     * Aufgaben bleiben davon unberührt: Eine abgeschlossene Aufgabe hat im
     * Kalender nichts verloren, egal wie weit ihr Enddatum zurückliegt.
     */
    public function testCompletedTasksStayOutOfTheFeed(): void
    {
        $this->user->calendar_task_feed = User::CALENDAR_TASK_FEED_COMBINED;
        $this->user->save();

        $project = Project::create([
            'name' => 'Aufgaben im Abo ' . bin2hex(random_bytes(4)),
            'description' => 'Projekt für den Kalender-Test',
        ]);

        $done = Task::create([
            'project_id' => $project->id,
            'name' => 'Längst erledigt',
            'status' => 'Abgeschlossen',
            'priority' => 'Mittel',
            'end_date' => Carbon::now()->subDays(20)->toDateString(),
            'created_by' => $this->user->id,
        ]);
        $done->assignees()->sync([$this->user->id]);

        $open = Task::create([
            'project_id' => $project->id,
            'name' => 'Noch offen',
            'status' => 'Offen',
            'priority' => 'Mittel',
            'end_date' => Carbon::now()->subDays(20)->toDateString(),
            'created_by' => $this->user->id,
        ]);
        $open->assignees()->sync([$this->user->id]);

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        $this->assertStringNotContainsString('task-' . $done->id . '@', $ics);
        $this->assertStringContainsString('task-' . $open->id . '@', $ics);
    }

    private function createEvent(string $title, int $dayOffset): Event
    {
        $day = Carbon::now()->addDays($dayOffset);

        $event = Event::create([
            'title' => $title,
            'starts_at' => $day->copy()->setTime(19, 0),
            'ends_at' => $day->copy()->setTime(21, 0),
            'type' => 'Probe',
        ]);

        (new EventAudienceService())->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $this->user->id],
        ]);

        return $event->fresh();
    }
}
