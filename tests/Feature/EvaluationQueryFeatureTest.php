<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\EvaluationQuery;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Abfragen der Anmeldeauswertung stehen in der Abfrageschicht, nicht im
 * EvaluationController.
 *
 * Der Controller fragte `user_voice_groups` und die Anwesenheiten selbst ab. Die
 * Regeln darin - erste Stimmgruppe zählt, ein Termin ohne Anwesende bekommt
 * ausdrücklich 0 statt keinem Eintrag - gelten unverändert und stehen jetzt hier,
 * ohne Controller prüfbar.
 */
final class EvaluationQueryFeatureTest extends TestCase
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

    private function user(): User
    {
        return User::create([
            'first_name' => 'Auswertung',
            'last_name' => 'Abfrage',
            'email' => 'auswertung.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    private function event(): Event
    {
        return Event::create([
            'title' => 'Abfragetermin ' . bin2hex(random_bytes(3)),
            'starts_at' => Carbon::now()->subDay()->setTime(19, 0),
            'ends_at' => Carbon::now()->subDay()->setTime(21, 0),
            'attendance_required' => true,
        ]);
    }

    public function testEventsWithoutAnyonePresentCountZero(): void
    {
        $event = $this->event();

        self::assertSame(
            [(int) $event->id => 0],
            (new EvaluationQuery())->presentCountsByEvent([(int) $event->id], [(int) $event->id => []])
        );
    }

    public function testOnlyPresentRowsAreCounted(): void
    {
        $event = $this->event();
        $userIds = [];
        foreach ([Attendance::STATUS_PRESENT, Attendance::STATUS_PRESENT, Attendance::STATUS_UNKNOWN] as $status) {
            $user = $this->user();
            $userIds[] = (int) $user->id;
            Attendance::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'status' => $status,
            ]);
        }

        self::assertSame(
            [(int) $event->id => 2],
            (new EvaluationQuery())->presentCountsByEvent([(int) $event->id], [(int) $event->id => $userIds])
        );
    }

    /**
     * Anwesend wird nur gezählt, wen die Zielgruppe des Termins heute noch
     * umfasst - dieselbe Menge, aus der die Zusagen und der Rücklauf daneben
     * gerechnet werden.
     *
     * Beim Eintragen stimmten beide Mengen überein: AttendanceController::save()
     * lässt nur Einträge für berechtigte Mitglieder durch. Der Eintrag bleibt
     * aber stehen, wenn die Zielgruppe sich später ändert - jemand verlässt das
     * Projekt oder wird archiviert. Danach stand in der Spalte "Anwesend (Ist)"
     * eine Zahl, die größer sein konnte als die Bezugsgröße der Zusagen
     * daneben, und die drei Zahlen einer Zeile ließen sich nicht vergleichen.
     */
    public function testPresentCountsIgnoreWhoIsNoLongerInTheAudience(): void
    {
        $event = $this->event();
        $member = $this->user();
        $gone = $this->user();

        foreach ([$member, $gone] as $person) {
            Attendance::create([
                'event_id' => $event->id,
                'user_id' => $person->id,
                'status' => Attendance::STATUS_PRESENT,
            ]);
        }

        $counts = (new EvaluationQuery())->presentCountsByEvent(
            [(int) $event->id],
            [(int) $event->id => [(int) $member->id]]
        );

        self::assertSame([(int) $event->id => 1], $counts);
    }

    /**
     * Fehlt die Zielgruppe eines Termins in der Zuordnung, bleibt die Zahl 0 und
     * wird nicht aus Verlegenheit ungefiltert gezählt: Kein Eintrag heißt
     * "niemand gehört dazu", und das ist die sichere Richtung.
     */
    public function testAnEventWithoutAnAudienceEntryCountsZero(): void
    {
        $event = $this->event();
        Attendance::create([
            'event_id' => $event->id,
            'user_id' => $this->user()->id,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        self::assertSame(
            [(int) $event->id => 0],
            (new EvaluationQuery())->presentCountsByEvent([(int) $event->id], [])
        );
    }

    public function testNoEventsMeansNoQuery(): void
    {
        $query = new EvaluationQuery();

        self::assertSame([], $query->presentCountsByEvent([], []));
        self::assertSame([], $query->registrationStatusByEvent([]));
        self::assertSame([], $query->firstVoiceGroupNameByUser([]));
    }

    public function testTheVoiceGroupWithTheLowestIdWins(): void
    {
        $user = $this->user();
        $suffix = bin2hex(random_bytes(3));
        $first = VoiceGroup::create(['name' => 'Erste ' . $suffix]);
        $second = VoiceGroup::create(['name' => 'Zweite ' . $suffix]);
        // Absichtlich in umgekehrter Reihenfolge zugeordnet.
        $user->voiceGroups()->attach($second->id, ['sub_voice_id' => null]);
        $user->voiceGroups()->attach($first->id, ['sub_voice_id' => null]);

        self::assertSame(
            [(int) $user->id => 'Erste ' . $suffix],
            (new EvaluationQuery())->firstVoiceGroupNameByUser([(int) $user->id])
        );
    }

    public function testRegistrationStatusIsGroupedByEventAndMember(): void
    {
        $event = $this->event();
        $user = $this->user();
        EventRegistration::create(['event_id' => $event->id, 'user_id' => $user->id, 'status' => 'maybe']);

        self::assertSame(
            [(int) $event->id => [(int) $user->id => 'maybe']],
            (new EvaluationQuery())->registrationStatusByEvent([(int) $event->id])
        );
    }
}
