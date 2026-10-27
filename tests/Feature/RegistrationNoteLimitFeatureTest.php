<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Die Begründung einer Anmeldung passt in die Spalte - und ein Statuswert, der
 * als Array ankommt, wird abgewiesen statt zu "Array" verwandelt.
 *
 * Beides stand bisher nur im Formular (`maxlength="255"`, ein Auswahlknopf).
 * Ein Aufruf, der nicht von dort kommt, lief damit in die Spaltenbreite: Die
 * Zeile wurde nicht geschrieben, und der Vertretungseintrag nahm die ganze
 * Transaktion mit - alle übrigen Mitglieder der abgeschickten Liste ebenso.
 * Die Anwesenheitsliste kappt dieselbe Notiz seit immer
 * (AttendanceController::buildAttendanceChanges).
 */
class RegistrationNoteLimitFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;

    private Event $event;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $this->event = Event::create([
            'title' => 'Probe Notizgrenze',
            'starts_at' => Carbon::now()->addDays(6)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(6)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);
        $this->openToEveryone($this->event);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "note_limit_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Nora',
            'last_name' => 'Notiz',
            'is_active' => 1,
        ]);
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            $this->createAppTwig('/registrations'),
            new AttendanceScopeService($_SESSION),
            new NullLogger(),
            new \App\Services\NameFormatterService()
        );
    }

    public function testOwnRegistrationStoresAnOverlongNoteCutToTheColumnWidth(): void
    {
        $member = $this->createUser();
        $_SESSION['user_id'] = (int) $member->id;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id, [
            'status' => 'no',
            'note' => str_repeat('ä', 400),
        ]);
        $response = $this->controller()->save($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($_SESSION['error'] ?? null, 'Eine lange Begründung darf die Anmeldung nicht verhindern.');

        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        $this->assertSame('no', $row->status);
        $this->assertSame(255, mb_strlen((string) $row->note));
    }

    public function testAnOverlongProxyNoteDoesNotDiscardTheWholeList(): void
    {
        $manager = $this->createUser();
        $first = $this->createUser();
        $second = $this->createUser();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_attendance_all'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [
                (string) $first->id => 'yes',
                (string) $second->id => 'no',
            ],
            'note' => [(string) $second->id => str_repeat('b', 400)],
        ]);
        $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
            'event_id' => (string) $this->event->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($_SESSION['error'] ?? null, 'Eine lange Notiz darf die übrigen Einträge nicht verwerfen.');

        $this->assertSame('yes', (string) EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $first->id)->firstOrFail()->status);

        $row = EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $second->id)->firstOrFail();
        $this->assertSame('no', (string) $row->status);
        $this->assertSame(255, mb_strlen((string) $row->note));
    }

    /**
     * `registration[<id>][]=yes` kommt als Array an. Ein `(string)`-Guss machte
     * daraus "Array" samt PHP-Warnung; abgewiesen wird die Zeile ohnehin, also
     * soll sie gar nicht erst in die Umwandlung laufen.
     */
    public function testAProxyStatusThatArrivesAsAnArrayIsIgnoredWithoutAWarning(): void
    {
        $manager = $this->createUser();
        $member = $this->createUser();

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_attendance_all'] = true;

        $request = $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
            'registration' => [(string) $member->id => ['yes']],
        ]);

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException('PHP-Meldung: ' . $message);
        }, E_ALL);

        try {
            $response = $this->controller()->saveProxy($request, $this->makeResponse(), [
                'event_id' => (string) $this->event->id,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(
            $_SESSION['error'] ?? null,
            'Eine Zeile mit Array-Status soll stillschweigend wegfallen, nicht den Speichervorgang abbrechen.'
        );
        $this->assertSame(0, EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $member->id)->count());
    }
}
