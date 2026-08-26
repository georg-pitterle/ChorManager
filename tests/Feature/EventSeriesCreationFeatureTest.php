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
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Serienanlage in EventController::save().
 *
 * Beim Anlegen wurde der Anmeldeschluss bisher verworfen (immer null), während
 * die Serienänderung ihn als Vorlauf übernahm - zwei gegensätzliche Antworten im
 * selben Controller. Und der Monatstakt lief über "+1 Monat" auf dem zuletzt
 * erzeugten Datum, wodurch ein Termin am 31. über die Monate abwanderte.
 */
final class EventSeriesCreationFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private EventController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn(ResponseInterface $response): ResponseInterface => $response
        );

        $this->controller = new EventController($twig, new NameFormatterService(), new NullLogger());
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

    /**
     * @param array<string, mixed> $overrides
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    private function createSeries(array $overrides = [])
    {
        $before = EventSeries::max('id') ?? 0;

        $body = array_merge([
            'title' => 'Serientest ' . bin2hex(random_bytes(3)),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'repeat' => '1',
            'frequency' => 'weekly',
            'recurrence_interval' => '1',
            'weekdays' => ['1'],
        ], $overrides);

        $this->controller->create(
            $this->makeRequest('POST', '/events', $body),
            $this->makeResponse()
        );

        $series = EventSeries::where('id', '>', $before)->orderBy('id', 'desc')->first();
        self::assertNotNull($series, 'Die Serie muss angelegt worden sein.');

        return Event::where('series_id', $series->id)->orderBy('starts_at')->get();
    }

    public function testSeriesCreationCarriesTheRegistrationDeadlineAsALeadTime(): void
    {
        $firstDay = Carbon::now()->addWeek()->next(Carbon::MONDAY)->format('Y-m-d');

        $events = $this->createSeries([
            'starts_at' => $firstDay,
            'series_end_date' => Carbon::parse($firstDay)->addDays(15)->format('Y-m-d'),
            'registration_enabled' => '1',
            // Zwei Tage vor Terminbeginn.
            'registration_deadline' => Carbon::parse($firstDay . ' 19:00')->subDays(2)->format('Y-m-d\TH:i'),
        ]);

        $this->assertGreaterThan(1, $events->count());

        foreach ($events as $event) {
            $this->assertNotNull(
                $event->registration_deadline,
                'Jeder Serientermin braucht seinen eigenen Anmeldeschluss.'
            );
            $this->assertSame(
                2 * 24 * 60,
                (int) Carbon::parse($event->registration_deadline)->diffInMinutes(Carbon::parse($event->starts_at)),
                'Der Vorlauf muss bei jedem Termin derselbe sein.'
            );
        }
    }

    public function testMonthlySeriesOnTheLastDayDoesNotDriftAcrossMonths(): void
    {
        $events = $this->createSeries([
            'starts_at' => '2026-01-31',
            'series_end_date' => '2026-05-01',
            'frequency' => 'monthly',
            'weekdays' => [],
        ]);

        $days = $events->map(
            static fn(Event $event): string => Carbon::parse($event->starts_at)->format('Y-m-d')
        )->all();

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $days);
    }

    public function testWeekdaysAreNotStoredForANonWeeklySeries(): void
    {
        $before = EventSeries::max('id') ?? 0;

        $this->controller->create(
            $this->makeRequest('POST', '/events', [
                'title' => 'Monatstermin',
                'starts_at' => '2026-01-15',
                'start_time' => '19:00',
                'end_time' => '21:00',
                'repeat' => '1',
                'frequency' => 'monthly',
                'recurrence_interval' => '1',
                'weekdays' => ['1', '4'],
                'series_end_date' => '2026-03-01',
            ]),
            $this->makeResponse()
        );

        $series = EventSeries::where('id', '>', $before)->orderBy('id', 'desc')->first();

        $this->assertNull($series->weekdays, 'Wochentage gehören nur zum Wochentakt.');
    }
}
