<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventSeries;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Serienbearbeitung in EventController::update().
 *
 * Beim Anwenden auf die Serie wurde bisher nur die Uhrzeit übernommen; ein
 * geändertes Datum und der Anmeldeschluss fielen kommentarlos unter den Tisch,
 * während die Erfolgsmeldung erschien.
 */
final class EventSeriesUpdateFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private EventController $controller;
    private EventSeries $series;
    /** @var array<int, Event> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn(ResponseInterface $response): ResponseInterface => $response
        );

        $this->controller = new EventController($twig, new NameFormatterService());

        $this->series = EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
        ]);

        // Drei wöchentliche Termine, jeweils 19:00-21:00.
        $this->events = [];
        foreach ([7, 14, 21] as $index => $daysAhead) {
            $start = Carbon::now()->addDays($daysAhead)->setTime(19, 0);
            $this->events[$index] = Event::create([
                'title' => 'Wochenprobe',
                'starts_at' => $start,
                'ends_at' => (clone $start)->setTime(21, 0),
                'type' => 'Probe',
                'series_id' => $this->series->id,
                'registration_enabled' => true,
                'attendance_required' => true,
            ]);
        }

        $_SESSION = ['user_id' => 1, 'can_manage_events' => true];
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

    /** @param array<string, mixed> $overrides */
    private function update(Event $event, array $overrides = []): ResponseInterface
    {
        $body = array_merge([
            'title' => 'Wochenprobe',
            'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'location' => '',
            'registration_enabled' => '1',
            'attendance_required' => '1',
            'update_series' => '1',
        ], $overrides);

        return $this->controller->update(
            $this->makeRequest('POST', '/events/' . $event->id, $body),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );
    }

    public function testChangingTheDateOfASeriesIsRejectedInsteadOfSilentlyIgnored(): void
    {
        $first = $this->events[0];
        $originalDate = Carbon::parse($first->starts_at)->format('Y-m-d');

        $this->update($first, [
            'starts_at' => Carbon::parse($first->starts_at)->addDay()->format('Y-m-d'),
        ]);

        $first->refresh();

        $this->assertSame(
            $originalDate,
            Carbon::parse($first->starts_at)->format('Y-m-d'),
            'Das Datum darf sich bei einer Serienänderung nicht ändern.'
        );
        $this->assertStringContainsString('nur die Uhrzeit', (string) $_SESSION['error']);
        $this->assertArrayNotHasKey('success', $_SESSION, 'Es darf keine Erfolgsmeldung erscheinen.');
    }

    public function testChangingOnlyTheTimeStillUpdatesTheWholeSeries(): void
    {
        $this->update($this->events[0], ['start_time' => '18:30', 'end_time' => '20:30']);

        foreach ($this->events as $event) {
            $event->refresh();
            $this->assertSame('18:30', Carbon::parse($event->starts_at)->format('H:i'));
            $this->assertSame('20:30', Carbon::parse($event->ends_at)->format('H:i'));
        }

        $this->assertStringContainsString('Serie', (string) $_SESSION['success']);
    }

    public function testRegistrationDeadlineIsAppliedAsALeadTimePerEvent(): void
    {
        $first = $this->events[0];
        // Anmeldeschluss zwei Tage vor dem ersten Termin.
        $deadline = Carbon::parse($first->starts_at)->subDays(2);

        $this->update($first, [
            'registration_deadline' => $deadline->format('Y-m-d\TH:i'),
        ]);

        foreach ($this->events as $event) {
            $event->refresh();

            $this->assertNotNull(
                $event->registration_deadline,
                'Der Anmeldeschluss darf bei einer Serienänderung nicht verworfen werden.'
            );
            $this->assertSame(
                Carbon::parse($event->starts_at)->subDays(2)->format('Y-m-d H:i'),
                Carbon::parse($event->registration_deadline)->format('Y-m-d H:i'),
                'Jeder Termin bekommt denselben Vorlauf, nicht denselben absoluten Zeitpunkt.'
            );
        }

        // Der entscheidende Unterschied: die Termine schließen nicht alle gleichzeitig.
        $this->assertNotSame(
            Carbon::parse($this->events[0]->registration_deadline)->format('Y-m-d H:i'),
            Carbon::parse($this->events[1]->registration_deadline)->format('Y-m-d H:i')
        );
    }

    public function testClearingTheDeadlineClearsItForTheWholeSeries(): void
    {
        $first = $this->events[0];
        $this->update($first, [
            'registration_deadline' => Carbon::parse($first->starts_at)->subDay()->format('Y-m-d\TH:i'),
        ]);
        $this->assertNotNull($this->events[1]->refresh()->registration_deadline);

        $_SESSION = ['user_id' => 1, 'can_manage_events' => true];
        $this->update($first, ['registration_deadline' => '']);

        foreach ($this->events as $event) {
            $this->assertNull(
                $event->refresh()->registration_deadline,
                'Ein geleerter Anmeldeschluss muss auch in der Serie verschwinden.'
            );
        }
    }

    public function testSingleEventUpdateStillMovesTheDate(): void
    {
        $single = Event::create([
            'title' => 'Einzeltermin',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => false,
            'attendance_required' => false,
        ]);
        $newDate = Carbon::now()->addDays(6)->format('Y-m-d');

        $this->controller->update(
            $this->makeRequest('POST', '/events/' . $single->id, [
                'title' => 'Einzeltermin',
                'starts_at' => $newDate,
                'start_time' => '19:00',
                'end_time' => '21:00',
                'location' => '',
            ]),
            $this->makeResponse(),
            ['id' => (string) $single->id]
        );

        $this->assertSame($newDate, Carbon::parse($single->refresh()->starts_at)->format('Y-m-d'));
    }
}
