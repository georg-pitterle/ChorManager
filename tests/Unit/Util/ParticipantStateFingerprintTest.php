<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Util\ParticipantStateFingerprint;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Fingerabdruck trägt die optimistische Sperre von Anwesenheitsliste und
 * Vertretungseinträgen. Beide bauen ihn über dieselbe Stelle, damit die Regel
 * nicht zweimal im Projekt steht und beim nächsten Eingriff auseinanderläuft.
 *
 * Festgehalten wird, was die Sperre tragfähig macht: Der Fingerabdruck muss
 * zwei verschiedene Stände unterscheiden, auch wenn `note` als freier Text
 * eines Mitglieds eine weitere Zeile nachahmt. Dafür stehen die Trennzeichen
 * \x1f zwischen den Feldern und \x1e zwischen den Zeilen - sie sind der Grund
 * für die Eindeutigkeit und beim Lesen des Codes nicht zu sehen.
 */
final class ParticipantStateFingerprintTest extends TestCase
{
    private Event $event;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $this->event = Event::create([
            'title' => 'Probe Fingerabdruck',
            'starts_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(21, 0),
            'type' => 'Probe',
            'attendance_required' => true,
            'registration_enabled' => true,
        ]);

        $this->first = $this->createUser();
        $this->second = $this->createUser();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            // naming:ascii - E-Mail-Adressen bleiben technisch ASCII.
            'email' => "fingerprint_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    /** @return list<int> */
    private function userIds(): array
    {
        return [(int) $this->first->id, (int) $this->second->id];
    }

    public function testEmptyUserListHasAStableFingerprint(): void
    {
        $this->assertSame(
            hash('sha256', ''),
            ParticipantStateFingerprint::of(Attendance::class, (int) $this->event->id, [])
        );
    }

    public function testFingerprintChangesWithTheStoredState(): void
    {
        $before = ParticipantStateFingerprint::of(Attendance::class, (int) $this->event->id, $this->userIds());

        Attendance::create([
            'event_id' => (int) $this->event->id,
            'user_id' => (int) $this->first->id,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        $this->assertNotSame(
            $before,
            ParticipantStateFingerprint::of(Attendance::class, (int) $this->event->id, $this->userIds()),
            'Ein geänderter Stand muss einen anderen Fingerabdruck ergeben.'
        );
    }

    /**
     * Stand A: nur die erste Person hat einen Eintrag, ihre Notiz lautet
     * "<Kennung der zweiten Person><Status>". Stand B: beide haben denselben
     * Status, beide ohne Notiz. Ohne Trennzeichen reihen sich beide Stände zu
     * derselben Zeichenkette - der Fingerabdruck wäre gleich, und die Sperre
     * hielte den fremden Stand für den eigenen.
     */
    public function testNoteCannotImitateAnotherRowInAttendance(): void
    {
        $eventId = (int) $this->event->id;
        $status = Attendance::STATUS_PRESENT;

        Attendance::create([
            'event_id' => $eventId,
            'user_id' => (int) $this->first->id,
            'status' => $status,
            'note' => (string) $this->second->id . $status,
        ]);
        $stateA = ParticipantStateFingerprint::of(Attendance::class, $eventId, $this->userIds());

        Attendance::where('event_id', $eventId)->where('user_id', $this->first->id)->update(['note' => null]);
        Attendance::create([
            'event_id' => $eventId,
            'user_id' => (int) $this->second->id,
            'status' => $status,
        ]);
        $stateB = ParticipantStateFingerprint::of(Attendance::class, $eventId, $this->userIds());

        $this->assertNotSame($stateA, $stateB, 'Eine Notiz darf keine weitere Zeile nachahmen können.');
    }

    /** Dieselbe Eigenschaft für die Anmeldungen - sie teilen die Stelle. */
    public function testNoteCannotImitateAnotherRowInRegistrations(): void
    {
        $eventId = (int) $this->event->id;
        $status = EventRegistration::STATUS_YES;

        EventRegistration::create([
            'event_id' => $eventId,
            'user_id' => (int) $this->first->id,
            'status' => $status,
            'note' => (string) $this->second->id . $status,
        ]);
        $stateA = ParticipantStateFingerprint::of(EventRegistration::class, $eventId, $this->userIds());

        EventRegistration::where('event_id', $eventId)->where('user_id', $this->first->id)->update(['note' => null]);
        EventRegistration::create([
            'event_id' => $eventId,
            'user_id' => (int) $this->second->id,
            'status' => $status,
        ]);
        $stateB = ParticipantStateFingerprint::of(EventRegistration::class, $eventId, $this->userIds());

        $this->assertNotSame($stateA, $stateB, 'Eine Notiz darf keine weitere Zeile nachahmen können.');
    }
}
