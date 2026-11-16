<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\ParticipationWriter;
use App\Util\ParticipantStateFingerprint;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Anwesenheiten und Vertretungsanmeldungen schreibt der ParticipationWriter, und
 * ein Fehler dabei erreicht die Seite als Meldung.
 *
 * Beide Controller öffneten die Transaktion selbst (`beginTransaction`, `commit`,
 * `rollBack` von Hand). Die Regeln bewachen weiter AttendanceSaveRobustnessFeatureTest,
 * AttendanceFeatureTest und RegistrationProxyFeatureTest über den Controller; dieser
 * Test hält nur den Fehlerweg fest: Wirft der Dienst, kommt "Fehler beim Speichern"
 * zurück, und nichts steht in der Datenbank.
 */
final class ParticipationWriterWiringFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use BuildsControllers;

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

        $_SESSION = [];
        parent::tearDown();
    }

    /** Ein Schreibdienst, der bei jedem Aufruf scheitert. */
    private function failingWriter(): ParticipationWriter
    {
        return new class extends ParticipationWriter {
            public function saveAttendances(int $eventId, array $rowsToWrite, array $userIdsToClear): void
            {
                throw new RuntimeException('Anwesenheit nicht schreibbar');
            }

            public function saveProxyRegistrations(
                int $eventId,
                array $entries,
                array $userIdsToClear,
                int $actorId
            ): void {
                throw new RuntimeException('Anmeldung nicht schreibbar');
            }
        };
    }

    private function member(): User
    {
        return User::create([
            'first_name' => 'Schreib',
            'last_name' => 'Dienst',
            'email' => 'schreibdienst.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    public function testAFailingAttendanceWriteIsReportedAndNothingIsStored(): void
    {
        $event = Event::create([
            'title' => 'Probe für den Schreibdienst',
            'starts_at' => Carbon::now()->subDay()->setTime(19, 0),
            'ends_at' => Carbon::now()->subDay()->setTime(21, 0),
            'attendance_required' => true,
        ]);
        $this->openToEveryone($event);
        $member = $this->member();
        $_SESSION = ['user_id' => (int) $member->id, 'can_manage_attendance_all' => true];

        $controller = $this->attendanceController([
            'view' => $this->createStub(Twig::class),
            'writer' => $this->failingWriter(),
        ]);

        $response = $controller->save(
            $this->makeRequest('POST', '/attendance/' . $event->id, [
                'attendance' => [(string) $member->id => Attendance::STATUS_PRESENT],
                'note' => [],
                'state_hash' => ParticipantStateFingerprint::of(
                    Attendance::class,
                    (int) $event->id,
                    [(int) $member->id]
                ),
            ]),
            $this->makeResponse(),
            ['event_id' => (string) $event->id]
        );

        $this->assertRedirect($response, '/attendance/' . $event->id);
        self::assertStringStartsWith(
            'Fehler beim Speichern der Anwesenheiten.',
            (string) ($_SESSION['error'] ?? '')
        );
        self::assertSame(0, Attendance::where('event_id', $event->id)->count());
    }

    public function testAFailingProxyRegistrationWriteIsReportedAndNothingIsStored(): void
    {
        $event = Event::create([
            'title' => 'Anmeldung für den Schreibdienst',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'registration_enabled' => true,
        ]);
        $this->openToEveryone($event);
        $admin = $this->member();
        $member = $this->member();
        $_SESSION = ['user_id' => (int) $admin->id, 'can_manage_attendance_all' => true];

        $controller = $this->registrationController([
            'view' => $this->createStub(Twig::class),
            'writer' => $this->failingWriter(),
        ]);

        $controller->saveProxy(
            $this->makeRequest('POST', '/registrations/' . $event->id . '/proxy', [
                'registration' => [(string) $member->id => 'maybe'],
                'note' => [],
                'state_hash' => ParticipantStateFingerprint::of(
                    EventRegistration::class,
                    (int) $event->id,
                    [(int) $member->id]
                ),
            ]),
            $this->makeResponse(),
            ['event_id' => (string) $event->id]
        );

        self::assertSame('Fehler beim Speichern der Vertretungseinträge.', $_SESSION['error'] ?? null);
        self::assertSame(0, EventRegistration::where('event_id', $event->id)->count());
    }
}
