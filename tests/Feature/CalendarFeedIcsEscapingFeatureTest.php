<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\User;
use App\Services\CalendarFeedService;
use App\Services\EventAudienceService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der abonnierte Kalender muss den Regeln von RFC 5545 folgen, sonst verwirft
 * das Kalenderprogramm nicht nur den einen Termin, sondern die ganze Datei.
 *
 * Zwei Stellen sind dabei heikel, weil ihr Inhalt aus einem Formular stammt:
 * Sonderzeichen im Text und die Länge einer Zeile.
 */
final class CalendarFeedIcsEscapingFeatureTest extends TestCase
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
            'email' => 'ics.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'Ines',
            'last_name' => 'Kalender',
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

    /**
     * Der Backslash ist in RFC 5545 selbst das Fluchtzeichen und muss deshalb
     * verdoppelt werden. Ohne das liest ein Kalenderprogramm "C:\Noten" als
     * angefangene Fluchtsequenz und verschluckt das folgende Zeichen.
     */
    public function testABackslashInTheTitleIsDoubled(): void
    {
        $this->createEvent('Probe C:\\Noten', 'Pfarrsaal');

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        $this->assertStringContainsString('SUMMARY:Probe C:\\\\Noten', $this->unfold($ics));
    }

    /**
     * Ein Zeilenumbruch im Ort beendet die Inhaltszeile mitten im Wert. Alles
     * dahinter gilt dem Kalenderprogramm als eigene, unbekannte Eigenschaft.
     */
    public function testALineBreakInTheLocationDoesNotBreakTheContentLine(): void
    {
        $this->createEvent('Konzert', "Stadtsaal\nEingang West");

        $ics = $this->unfold($this->service->buildEventCalendar($this->user, self::BASE_URL));

        $this->assertStringContainsString('LOCATION:Stadtsaal\nEingang West', $ics);
        $this->assertStringNotContainsString("LOCATION:Stadtsaal\r\nEingang", $ics);

        foreach (explode("\r\n", trim($ics)) as $line) {
            $this->assertNotSame('Eingang West', $line, 'Der Ort ist in zwei Zeilen zerfallen.');
        }
    }

    /**
     * Der einzelne Wagenrücklauf zählt genauso; er kommt aus Formularen, die
     * ihre Eingabe nicht auf LF vereinheitlichen.
     */
    public function testACarriageReturnIsEscapedAsWell(): void
    {
        $this->createEvent("Auftritt\rmit Pause", 'Bühne');

        $ics = $this->unfold($this->service->buildEventCalendar($this->user, self::BASE_URL));

        $this->assertStringContainsString('SUMMARY:Auftritt\nmit Pause', $ics);
    }

    /**
     * Komma und Strichpunkt trennen in RFC 5545 mehrere Werte und müssen im
     * Text ihr Fluchtzeichen behalten.
     */
    public function testCommaAndSemicolonKeepTheirEscape(): void
    {
        $this->createEvent('Probe, Teil 1; kurz', 'Saal');

        $ics = $this->unfold($this->service->buildEventCalendar($this->user, self::BASE_URL));

        $this->assertStringContainsString('SUMMARY:Probe\, Teil 1\; kurz', $ics);
    }

    /**
     * RFC 5545 begrenzt eine Inhaltszeile auf 75 Oktette. Längere Zeilen werden
     * gefaltet: Umbruch, dann ein Leerzeichen als Fortsetzungszeichen.
     */
    public function testLongLinesAreFoldedAndUnfoldToTheOriginalValue(): void
    {
        $title = 'Jahresabschlusskonzert des gesamten Chores mit Orchester und Gastsolisten aus Innsbruck';
        $this->createEvent($title, 'Congress');

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        foreach (explode("\r\n", trim($ics)) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'Zeile länger als 75 Oktette: ' . $line);
        }

        $this->assertStringContainsString('SUMMARY:' . $title, $this->unfold($ics));
    }

    /**
     * Gefaltet wird zwischen Zeichen, nie mitten in einem UTF-8-Zeichen - sonst
     * stehen zwei kaputte Halbbytes in der Datei.
     */
    public function testFoldingNeverSplitsAMultibyteCharacter(): void
    {
        $title = str_repeat('ü', 60) . ' Chorprobe';
        $this->createEvent($title, 'Saal');

        $ics = $this->service->buildEventCalendar($this->user, self::BASE_URL);

        $this->assertTrue(mb_check_encoding($this->unfold($ics), 'UTF-8'));
        $this->assertStringContainsString('SUMMARY:' . $title, $this->unfold($ics));
    }

    /**
     * Entfaltet die Datei wieder, wie es ein Kalenderprogramm tut.
     */
    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    private function createEvent(string $title, string $location): Event
    {
        $event = Event::create([
            'title' => $title,
            'location' => $location,
            'starts_at' => Carbon::now()->addDays(4)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(4)->setTime(21, 0),
            'type' => 'Probe',
        ]);

        (new EventAudienceService())->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $this->user->id],
        ]);

        return $event->fresh();
    }
}
