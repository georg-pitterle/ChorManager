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
            (new EvaluationQuery())->presentCountsByEvent([(int) $event->id])
        );
    }

    public function testOnlyPresentRowsAreCounted(): void
    {
        $event = $this->event();
        foreach ([Attendance::STATUS_PRESENT, Attendance::STATUS_PRESENT, Attendance::STATUS_UNKNOWN] as $status) {
            Attendance::create([
                'event_id' => $event->id,
                'user_id' => $this->user()->id,
                'status' => $status,
            ]);
        }

        self::assertSame(
            [(int) $event->id => 2],
            (new EvaluationQuery())->presentCountsByEvent([(int) $event->id])
        );
    }

    public function testNoEventsMeansNoQuery(): void
    {
        $query = new EvaluationQuery();

        self::assertSame([], $query->presentCountsByEvent([]));
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
