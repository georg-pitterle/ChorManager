<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventSeries;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Serienänderung, Serienlöschung und Zielgruppen-Quellen.
 *
 * Eine Serienänderung überschrieb bisher jeden Folgetermin vollständig, auch
 * dort, wo jemand einen einzelnen Termin bewusst angepasst hatte. Welche
 * Feldgruppen übertragen werden, ist deshalb auswählbar.
 *
 * Das Löschen einer Serie erwischt alle Termine ab dem angeklickten - auch
 * vergangene. Die Meldung muss das benennen, statt "alle zukünftigen" zu
 * behaupten.
 */
final class EventSeriesScopeAndDeletionFeatureTest extends TestCase
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

        $this->controller = new EventController($twig, new NameFormatterService(), new NullLogger());

        $this->series = EventSeries::create([
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'weekdays' => '1',
            'end_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
        ]);

        // Ein vergangener und zwei künftige Termine derselben Serie.
        $this->events = [];
        foreach ([-7, 7, 14] as $index => $daysAhead) {
            $start = Carbon::now()->addDays($daysAhead)->setTime(19, 0);
            $this->events[$index] = Event::create([
                'title' => 'Wochenprobe',
                'starts_at' => $start,
                'ends_at' => (clone $start)->setTime(21, 0),
                'type' => 'Probe',
                'series_id' => $this->series->id,
                'location' => 'Pfarrsaal',
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
    private function updateSeries(Event $event, array $overrides = []): ResponseInterface
    {
        $body = array_merge([
            'title' => 'Wochenprobe',
            'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'location' => 'Pfarrsaal',
            'update_series' => '1',
            'attendance_required' => '1',
            'registration_enabled' => '1',
        ], $overrides);

        return $this->controller->update(
            $this->makeRequest('POST', '/events/' . $event->id . '/update', $body),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );
    }

    public function testSeriesUpdateAppliesEveryFieldGroupByDefault(): void
    {
        $this->updateSeries($this->events[1], [
            'title' => 'Generalprobe',
            'location' => 'Kirche',
        ]);

        $later = $this->events[2]->fresh();
        $this->assertSame('Generalprobe', (string) $later->title);
        $this->assertSame('Kirche', (string) $later->location);
    }

    public function testSeriesUpdateSkipsFieldGroupsThatWereNotSelected(): void
    {
        // Nur der Ort soll auf die Serie wirken; ein individuell vergebener Titel
        // eines Folgetermins muss stehen bleiben.
        $this->events[2]->title = 'Sonderprobe mit Orchester';
        $this->events[2]->save();

        $this->updateSeries($this->events[1], [
            'title' => 'Generalprobe',
            'location' => 'Kirche',
            'series_fields' => ['location'],
        ]);

        $later = $this->events[2]->fresh();
        $this->assertSame('Sonderprobe mit Orchester', (string) $later->title);
        $this->assertSame('Kirche', (string) $later->location);
    }

    public function testSeriesUpdateLeavesTheAudienceAloneWhenItIsNotSelected(): void
    {
        $voiceGroup = VoiceGroup::create(['name' => 'Serientest ' . bin2hex(random_bytes(4))]);
        (new EventAudienceService())->setSources($this->events[2], [
            ['type' => EventAudienceSource::TYPE_VOICE_GROUP, 'reference_id' => (int) $voiceGroup->id],
        ]);

        $this->updateSeries($this->events[1], [
            'location' => 'Kirche',
            'series_fields' => ['location'],
        ]);

        $sources = EventAudienceSource::where('event_id', $this->events[2]->id)->get();
        $this->assertCount(1, $sources, 'Die Zielgruppe eines Folgetermins darf nicht verschwinden.');
        $this->assertSame((int) $voiceGroup->id, (int) $sources->first()->reference_id);
    }

    public function testDeletingASeriesFromAPastEventReportsTheActualScope(): void
    {
        $this->controller->deleteSeries(
            $this->makeRequest('POST', '/events/' . $this->events[0]->id . '/delete-series'),
            $this->makeResponse(),
            ['id' => (string) $this->events[0]->id]
        );

        $this->assertSame(0, Event::where('series_id', $this->series->id)->count());
        $message = (string) ($_SESSION['success'] ?? '');
        $this->assertStringNotContainsString('zukünftigen', $message);
        $this->assertStringContainsString(
            Carbon::parse($this->events[0]->starts_at)->format('d.m.Y'),
            $message,
            'Die Meldung muss benennen, ab wann gelöscht wurde.'
        );
    }

    public function testAnArchivedMemberStaysAnAudienceSourceAcrossASave(): void
    {
        $member = User::create([
            'first_name' => 'Archiviert',
            'last_name' => 'Person',
            'email' => 'audience.archived.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 0,
        ]);

        $audienceService = new EventAudienceService();
        $audienceService->setSources($this->events[1], [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $member->id],
        ]);

        $sources = EventAudienceSource::where('event_id', $this->events[1]->id)->get();
        $this->assertCount(1, $sources, 'Eine namentliche Zielgruppe darf ein archiviertes Mitglied nicht verlieren.');

        // Gezählt wird es trotzdem nicht - dafür sorgt der is_active-Filter der Auflösung.
        $this->assertCount(0, $this->events[1]->fresh()->eligibleUsersQuery()->get());
    }
}
