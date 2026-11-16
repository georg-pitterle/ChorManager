<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventSeries;
use App\Services\EventAudienceService;
use App\Services\EventWriteService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Bootstrap;

/**
 * Termine und Terminserien entstehen und ändern sich ganz oder gar nicht.
 *
 * Der EventController schrieb Serie, Termine und je Termin die Zielgruppe
 * einzeln. Scheiterte ein Schritt - etwa die Zielgruppe des dritten von zehn
 * Terminen -, meldete der `catch` "konnte nicht angelegt werden", während ein
 * Teil der Serie schon in der Datenbank stand. Für die Serienänderung galt
 * dasselbe: Einige Folgetermine trugen den neuen Titel, andere den alten.
 */
final class EventWriteServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Ein Zielgruppendienst, dessen n-ter Aufruf von setAudience() scheitert.
     */
    private function audienceFailingOnCall(int $failOn): EventAudienceService
    {
        return new class ($failOn) extends EventAudienceService {
            private int $calls = 0;

            public function __construct(private readonly int $failOn)
            {
                parent::__construct();
            }

            public function setAudience(Event $event, array $conditionSets): void
            {
                if (++$this->calls === $this->failOn) {
                    throw new RuntimeException('Zielgruppe nicht schreibbar');
                }

                parent::setAudience($event, $conditionSets);
            }
        };
    }

    /** @return array<int, mixed> */
    private function audienceSets(): array
    {
        return (new EventAudienceService())->readRows(['audience' => [['all' => '1']]]);
    }

    /**
     * @return array{shared: array<string, mixed>, first: string, rule: array<string, mixed>}
     */
    private function weeklyThreeTimes(): array
    {
        $first = Carbon::now()->addWeek()->next(Carbon::MONDAY)->format('Y-m-d');

        return [
            'shared' => [
                'title' => 'Serientest ' . bin2hex(random_bytes(3)),
                'event_type_id' => null,
                'location' => 'Probenraum',
                'registration_enabled' => false,
                'attendance_required' => false,
            ],
            'first' => $first,
            'rule' => [
                'frequency' => 'weekly',
                'interval' => 1,
                'weekdays' => [1],
                'end' => CarbonImmutable::parse($first)->addDays(15),
            ],
        ];
    }

    public function testAFailingOccurrenceLeavesNoSeriesBehind(): void
    {
        $eventsBefore = Event::count();
        $seriesBefore = EventSeries::count();
        $plan = $this->weeklyThreeTimes();
        $service = new EventWriteService($this->audienceFailingOnCall(3));

        try {
            $service->createSeries(
                $plan['shared'],
                $plan['first'],
                '19:00',
                '21:00',
                null,
                $plan['rule'],
                $this->audienceSets()
            );
            self::fail('Der dritte Termin hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        self::assertSame($eventsBefore, Event::count(), 'Es dürfen keine Einzeltermine übrig bleiben.');
        self::assertSame($seriesBefore, EventSeries::count(), 'Die Serie darf nicht übrig bleiben.');
    }

    public function testAFailingSeriesUpdateChangesNoEvent(): void
    {
        $series = EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => '2030-11-04',
        ]);
        $events = [];
        foreach (['2030-10-21', '2030-10-28', '2030-11-04'] as $day) {
            $events[] = Event::create([
                'title' => 'Alt',
                'starts_at' => $day . ' 19:00:00',
                'ends_at' => $day . ' 21:00:00',
                'series_id' => $series->id,
                'registration_enabled' => false,
                'attendance_required' => false,
            ]);
        }
        $service = new EventWriteService($this->audienceFailingOnCall(2));

        try {
            $service->updateSeries(
                $events,
                ['title', 'audience'],
                [
                    'title' => 'Neu',
                    'event_type_id' => null,
                    'location' => '',
                    'registration_enabled' => false,
                    'attendance_required' => false,
                ],
                '2030-10-21 19:00:00',
                '2030-10-21 21:00:00',
                null,
                $this->audienceSets()
            );
            self::fail('Die Zielgruppe des zweiten Termins hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        foreach ($events as $event) {
            self::assertSame('Alt', Event::findOrFail($event->id)->title, 'Kein Termin darf halb geändert sein.');
        }
    }

    public function testAFailingAudienceLeavesNoSingleEventBehind(): void
    {
        $eventsBefore = Event::count();
        $service = new EventWriteService($this->audienceFailingOnCall(1));

        try {
            $service->createSingle([
                'title' => 'Einzeltermin',
                'starts_at' => '2030-10-21 19:00:00',
                'ends_at' => '2030-10-21 21:00:00',
                'event_type_id' => null,
                'location' => '',
                'registration_enabled' => false,
                'registration_deadline' => null,
                'attendance_required' => false,
            ], $this->audienceSets());
            self::fail('Die Zielgruppe hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        self::assertSame($eventsBefore, Event::count());
    }

    public function testAFailingAudienceLeavesASingleEventUnchanged(): void
    {
        $event = Event::create([
            'title' => 'Alt',
            'starts_at' => '2030-10-21 19:00:00',
            'ends_at' => '2030-10-21 21:00:00',
            'registration_enabled' => false,
            'attendance_required' => false,
        ]);
        $service = new EventWriteService($this->audienceFailingOnCall(1));

        try {
            $service->updateSingle($event, ['title' => 'Neu'], $this->audienceSets());
            self::fail('Die Zielgruppe hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        self::assertSame('Alt', Event::findOrFail($event->id)->title);
    }

    public function testSeriesCreationReturnsOneFreshEventPerOccurrence(): void
    {
        $plan = $this->weeklyThreeTimes();

        $events = (new EventWriteService())->createSeries(
            $plan['shared'],
            $plan['first'],
            '19:00',
            '21:00',
            null,
            $plan['rule'],
            $this->audienceSets()
        );

        self::assertCount(3, $events);
        $seriesIds = array_unique(array_map(static fn (Event $e): int => (int) $e->series_id, $events));
        self::assertCount(1, $seriesIds, 'Alle Termine gehören zu einer Serie.');
        $starts = array_map(static fn (Event $e): string => (string) $e->starts_at, $events);
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts, 'Die Rückgabe folgt der Reihenfolge der Termine.');
    }
}
