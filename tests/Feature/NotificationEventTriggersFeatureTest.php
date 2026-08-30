<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventSeries;
use App\Models\MailQueue;
use App\Models\User;
use App\Services\EventAudienceService;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NotificationService;
use App\Util\NotificationType;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Termin-Auslöser.
 *
 * Der wichtigste Fall ist die Serie: Wer ein Halbjahr Montagsproben anlegt,
 * darf nicht vierzig Mails je Mitglied auslösen. Genauso wichtig ist die
 * Gegenrichtung - eine Korrektur an der Beschreibung darf gar keine auslösen.
 */
final class NotificationEventTriggersFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private EventController $controller;
    private User $singer;
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->singer = $this->createUser('Sabine', 'Sopran');
        $this->organiser = $this->createUser('Otto', 'Organisator');

        // Stub statt Mock: Der Controller rendert hier nichts, was geprüft wird -
        // ein Mock ohne Erwartungen meldet das zu Recht als überflüssig.
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn (ResponseInterface $response): ResponseInterface => $response
        );

        $this->controller = new EventController(
            $twig,
            new NameFormatterService(),
            new NullLogger(),
            new NotificationService(
                new MailQueueService(),
                Twig::create(dirname(__DIR__, 2) . '/templates'),
                new NullLogger(),
                []
            )
        );

        $_SESSION = ['user_id' => (int) $this->organiser->id, 'can_manage_events' => true];
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

    public function testANewSingleEventNotifiesItsAudienceOnce(): void
    {
        $this->createEventViaController();

        $this->assertSame(1, $this->queuedCount());
        $this->assertSame(
            NotificationType::EVENT_CREATED,
            $this->firstQueued()->payload_json['notification_type']
        );
    }

    /**
     * Zwanzig Termine, eine Mail. Der Punkt der ganzen Sammelmail.
     */
    public function testAWholeSeriesProducesExactlyOneMailPerRecipient(): void
    {
        $firstDay = Carbon::now()->addWeek()->next(Carbon::MONDAY)->format('Y-m-d');
        $before = EventSeries::max('id') ?? 0;

        $this->controller->create(
            $this->makeRequest('POST', '/events', $this->body([
                'starts_at' => $firstDay,
                'repeat' => '1',
                'frequency' => 'weekly',
                'recurrence_interval' => '1',
                'weekdays' => ['1'],
                'series_end_date' => Carbon::parse($firstDay)->addWeeks(19)->format('Y-m-d'),
            ])),
            $this->makeResponse()
        );

        $series = EventSeries::where('id', '>', $before)->orderBy('id', 'desc')->first();
        $this->assertNotNull($series);
        $this->assertGreaterThan(
            10,
            Event::where('series_id', $series->id)->count(),
            'Die Serie muss viele Termine haben, sonst prüft der Test nichts.'
        );

        $this->assertSame(1, $this->queuedCount(), 'Eine Serie ist eine Mail, nicht eine je Termin.');
    }

    /**
     * Das Häkchen ist die Notbremse für Korrekturen - ohne es ginge jede
     * Tippfehler-Behebung an den ganzen Chor.
     */
    public function testUncheckingTheNotifyBoxKeepsItQuiet(): void
    {
        $this->controller->create(
            $this->makeRequest('POST', '/events', $this->body([
                'notify_members_present' => '1',
                // 'notify_members' fehlt - genau das sendet ein Browser bei
                // einem leeren Kästchen.
            ])),
            $this->makeResponse()
        );

        $this->assertSame(0, $this->queuedCount());
    }

    public function testAMovedEventNotifiesItsAudience(): void
    {
        $event = $this->createEventViaController();
        $this->clearQueue();

        $this->updateEvent($event, ['start_time' => '20:30', 'end_time' => '22:00']);

        $this->assertSame(1, $this->queuedCount());
        $this->assertSame(
            NotificationType::EVENT_CHANGED,
            $this->firstQueued()->payload_json['notification_type']
        );
    }

    public function testAChangedLocationNotifiesItsAudience(): void
    {
        $event = $this->createEventViaController();
        $this->clearQueue();

        $this->updateEvent($event, ['location' => 'Aula der Musikschule']);

        $this->assertSame(1, $this->queuedCount());
    }

    /**
     * Ein anderer Titel ist kein Grund, den ganzen Chor anzuschreiben - wer
     * jede Kleinigkeit meldet, gewöhnt den Leuten das Hinsehen ab.
     */
    public function testARenamedEventStaysQuiet(): void
    {
        $event = $this->createEventViaController();
        $this->clearQueue();

        $this->updateEvent($event, ['title' => 'Hauptprobe (korrigiert)']);

        $this->assertSame(0, $this->queuedCount());
    }

    public function testADeletedEventNotifiesTheAudienceItStillHad(): void
    {
        $event = $this->createEventViaController();
        $this->clearQueue();

        $this->controller->delete(
            $this->makeRequest('POST', '/events/' . $event->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertNull(Event::find($event->id));
        $this->assertSame(
            1,
            $this->queuedCount(),
            'Die Empfänger müssen vor dem Löschen ermittelt worden sein.'
        );
        $this->assertSame(
            NotificationType::EVENT_CANCELLED,
            $this->firstQueued()->payload_json['notification_type']
        );
    }

    public function testAPrivateNoteIsNotSentAround(): void
    {
        $event = $this->createEventViaController();
        $this->clearQueue();

        $this->controller->addNote(
            $this->makeRequest('POST', '/events/' . $event->id . '/notes', [
                'content' => 'Nur für mich.',
                'is_private' => '1',
            ]),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertSame(0, $this->queuedCount());
    }

    public function testAPublicNoteReachesTheAudience(): void
    {
        $event = $this->createEventViaController();
        $this->clearQueue();

        $this->controller->addNote(
            $this->makeRequest('POST', '/events/' . $event->id . '/notes', [
                'content' => 'Bitte Notenmappen mitbringen.',
            ]),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );

        $this->assertSame(1, $this->queuedCount());
        $this->assertSame(
            NotificationType::EVENT_NOTE,
            $this->firstQueued()->payload_json['notification_type']
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Hauptprobe ' . bin2hex(random_bytes(3)),
            'starts_at' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'location' => 'Pfarrsaal Zentrum',
            'audience_sources' => json_encode([
                ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $this->singer->id],
            ]),
        ], $overrides);
    }

    private function createEventViaController(): Event
    {
        $before = Event::max('id') ?? 0;

        $this->controller->create(
            $this->makeRequest('POST', '/events', $this->body()),
            $this->makeResponse()
        );

        $event = Event::where('id', '>', $before)->orderBy('id', 'desc')->first();
        $this->assertNotNull($event, 'Der Termin muss angelegt worden sein.');

        // Zielgruppe absichern: Ohne sie prüfte der Test nur, dass niemand
        // benachrichtigt wird.
        if ($event->audienceSources()->count() === 0) {
            (new EventAudienceService())->setSources($event, [
                ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $this->singer->id],
            ]);
        }

        return $event->fresh();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function updateEvent(Event $event, array $overrides): void
    {
        $body = $this->body(array_merge([
            'title' => $event->title,
            'starts_at' => Carbon::parse($event->starts_at)->format('Y-m-d'),
            'start_time' => Carbon::parse($event->starts_at)->format('H:i'),
            'end_time' => Carbon::parse($event->ends_at)->format('H:i'),
            'location' => (string) $event->location,
        ], $overrides));

        $this->controller->update(
            $this->makeRequest('POST', '/events/' . $event->id . '/update', $body),
            $this->makeResponse(),
            ['id' => (string) $event->id]
        );
    }

    private function queuedCount(): int
    {
        return MailQueue::where('recipient_email', $this->singer->email)->count();
    }

    private function firstQueued(): MailQueue
    {
        return MailQueue::where('recipient_email', $this->singer->email)->orderBy('id')->firstOrFail();
    }

    private function clearQueue(): void
    {
        MailQueue::where('recipient_email', $this->singer->email)->delete();
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'event.notify.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
