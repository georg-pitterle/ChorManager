<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventController;
use App\Models\Event;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Ein Anmeldeschluss nach dem Terminbeginn wird abgewiesen.
 *
 * Das Formular nahm ihn an, die Liste zeigte ihn als "Anmeldung bis ..." an -
 * gelten tat er nicht: `Event::isRegistrationOpen()` schließt die Anmeldung in
 * jedem Fall mit dem Terminbeginn. Wer eine spätere Frist eintrug, sah also
 * einen Schluss in der Zukunft und konnte trotzdem nicht mehr antworten, ohne
 * dass irgendwo stand warum.
 *
 * Aufgefallen ist es im Review-Lauf 86; die Entscheidung dazu lautet: Der
 * Terminbeginn gewinnt wie bisher, und das Formular nimmt eine Frist danach
 * nicht mehr an. Die Anzeige kann damit nicht mehr etwas anderes behaupten als
 * die Regel.
 *
 * Gleich zum Beginn ist erlaubt: Das ist genau die Vorgabe, die ein leeres Feld
 * ohnehin bedeutet ("optional, sonst Terminbeginn").
 */
final class RegistrationDeadlineAfterStartFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use BuildsControllers;

    private const ERROR = 'Der Anmeldeschluss darf nicht nach dem Terminbeginn liegen.';

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
        $_SESSION['can_manage_events'] = true;
    }

    protected function tearDown(): void
    {
        Event::where('title', 'like', 'Anmeldeschluss%')->delete();
        $_SESSION = [];
    }

    private function controller(): EventController
    {
        return $this->eventController(['view' => Twig::create(dirname(__DIR__) . '/../templates')]);
    }

    public function testAnlegenWeistEinenSchlussNachDemBeginnAb(): void
    {
        $start = Carbon::now()->addDays(5)->setTime(19, 0);

        $response = $this->controller()->create($this->makeRequest('POST', '/events', [
            'title' => 'Anmeldeschluss zu spät',
            'starts_at' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
            'audience' => [['all' => '1']],
            'registration_enabled' => '1',
            'registration_deadline' => $start->copy()->addHour()->format('Y-m-d\TH:i'),
        ]), $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::ERROR, $_SESSION['error'] ?? null);
        $this->assertNull(Event::where('title', 'Anmeldeschluss zu spät')->first());
    }

    /**
     * Auch für eine Serie. Dort wird der Schluss als Vorlauf zum jeweiligen
     * Beginn übernommen (EventWriteService) - ein Vorlauf in die Zukunft würde
     * jeden Termin der Serie gleich falsch beschriften, nicht nur einen.
     */
    public function testSerieWeistEinenSchlussNachDemBeginnAb(): void
    {
        $start = Carbon::now()->addDays(5)->setTime(19, 0);

        $response = $this->controller()->create($this->makeRequest('POST', '/events', [
            'title' => 'Anmeldeschluss Serie',
            'starts_at' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
            'audience' => [['all' => '1']],
            'repeat' => '1',
            'frequency' => 'weekly',
            'recurrence_interval' => '1',
            'weekdays' => [(string) $start->dayOfWeekIso],
            'series_end_date' => $start->copy()->addMonth()->format('Y-m-d'),
            'registration_enabled' => '1',
            'registration_deadline' => $start->copy()->addMinutes(30)->format('Y-m-d\TH:i'),
        ]), $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::ERROR, $_SESSION['error'] ?? null);
        $this->assertNull(Event::where('title', 'Anmeldeschluss Serie')->first());
    }

    public function testAendernWeistEinenSchlussNachDemBeginnAbUndLaesstDenAltenStehen(): void
    {
        $start = Carbon::now()->addDays(5)->setTime(19, 0);
        $keptDeadline = $start->copy()->subDays(2);

        $event = Event::create([
            'title' => 'Anmeldeschluss Bestand',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'registration_enabled' => true,
            'registration_deadline' => $keptDeadline,
            'attendance_required' => false,
        ]);
        $this->openToEveryone($event);

        $response = $this->controller()->update($this->makeRequest('POST', '/events/' . $event->id, [
            'title' => 'Anmeldeschluss Bestand',
            'starts_at' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
            'audience' => [['all' => '1']],
            'registration_enabled' => '1',
            'registration_deadline' => $start->copy()->addHour()->format('Y-m-d\TH:i'),
        ]), $this->makeResponse(), ['id' => (string) $event->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::ERROR, $_SESSION['error'] ?? null);

        $fresh = Event::find($event->id);
        $this->assertSame(
            $keptDeadline->format('Y-m-d H:i'),
            Carbon::parse($fresh->registration_deadline)->format('Y-m-d H:i')
        );
    }

    /**
     * Die Grenze selbst bleibt erlaubt, und ein Schluss davor sowieso - sonst
     * hätte die Prüfung den Normalfall mit abgewiesen.
     */
    public function testSchlussZumBeginnUndDavorBleibenErlaubt(): void
    {
        $start = Carbon::now()->addDays(5)->setTime(19, 0);

        foreach (['zum Beginn' => $start->copy(), 'davor' => $start->copy()->subDays(2)] as $case => $deadline) {
            $title = 'Anmeldeschluss ' . $case;
            unset($_SESSION['error']);

            $response = $this->controller()->create($this->makeRequest('POST', '/events', [
                'title' => $title,
                'starts_at' => $start->format('Y-m-d'),
                'start_time' => $start->format('H:i'),
                'end_time' => $start->copy()->addHours(2)->format('H:i'),
                'audience' => [['all' => '1']],
                'registration_enabled' => '1',
                'registration_deadline' => $deadline->format('Y-m-d\TH:i'),
            ]), $this->makeResponse());

            $this->assertSame(302, $response->getStatusCode(), $case);
            $this->assertNull($_SESSION['error'] ?? null, $case);

            $created = Event::where('title', $title)->first();
            $this->assertNotNull($created, $case);
            $this->assertSame(
                $deadline->format('Y-m-d H:i'),
                Carbon::parse($created->registration_deadline)->format('Y-m-d H:i'),
                $case
            );
        }
    }

    /**
     * Beide Masken sagen die Regel am Feld, nicht erst nach dem Abschicken.
     *
     * Der Hinweis stand bisher nur in der Bearbeiten-Maske und dort nur bei
     * einem Serientermin - `aria-describedby` zeigte für jeden anderen Termin
     * auf eine Kennung, die die Seite nicht hatte.
     */
    public function testBeideMaskenNennenDieRegelAmFeld(): void
    {
        foreach (['events/edit.twig', 'events/index.twig'] as $file) {
            $template = (string) file_get_contents(dirname(__DIR__) . '/../templates/' . $file);

            $this->assertStringContainsString('id="registration-deadline-help"', $template, $file);
            $this->assertStringContainsString('Nicht nach dem Terminbeginn', $template, $file);
        }
    }

    /**
     * Ohne freigeschaltete Anmeldung trägt das Feld keine Frist - der
     * Controller verwirft den Wert dann ohnehin, und eine Fehlermeldung über
     * ein Feld, das gar nicht gilt, wäre nur im Weg.
     */
    public function testOhneFreigeschalteteAnmeldungBleibtDasFeldWirkungslos(): void
    {
        $start = Carbon::now()->addDays(5)->setTime(19, 0);

        $response = $this->controller()->create($this->makeRequest('POST', '/events', [
            'title' => 'Anmeldeschluss ohne Anmeldung',
            'starts_at' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
            'audience' => [['all' => '1']],
            'registration_deadline' => $start->copy()->addHour()->format('Y-m-d\TH:i'),
        ]), $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($_SESSION['error'] ?? null);

        $created = Event::where('title', 'Anmeldeschluss ohne Anmeldung')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->registration_deadline);
    }
}
