<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Der Zustands-Hash der Vertretungseinträge muss zwei verschiedene Stände
 * zuverlässig auseinanderhalten.
 *
 * Gereiht wird je Zeile `user_id`, `status` und `note`, getrennt durch \x1f
 * zwischen den Feldern und \x1e zwischen den Zeilen. Diese Trennzeichen sind
 * der Grund, warum die Reihung eindeutig ist: `note` ist freier Text eines
 * Mitglieds. Fielen sie weg, ergäbe eine Begründung, die mit Kennung und Status
 * der nächsten Zeile beginnt, dieselbe Zeichenkette wie der Stand, in dem diese
 * Zeile wirklich existiert und die Begründung leer ist - zwei Stände mit
 * demselben Fingerabdruck, und saveProxy() überschriebe die Selbstauskunft eines
 * Mitglieds kommentarlos.
 *
 * Der Test hält diese Eigenschaft fest, weil sie in der Zeichenkette selbst
 * steckt und beim Lesen des Codes nicht auffällt.
 */
final class RegistrationProxyStateHashSeparatorFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;

    private Event $event;
    private User $manager;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $this->event = Event::create([
            'title' => 'Probe Hash-Trennzeichen',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'registration_enabled' => true,
        ]);
        $this->openToEveryone($this->event);

        $this->manager = $this->createUser();
        $this->first = $this->createUser();
        $this->second = $this->createUser();

        $_SESSION['user_id'] = (int) $this->manager->id;
        $_SESSION['can_manage_attendance_all'] = true;
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
            'email' => "proxy_hash_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            $this->createAppTwig('/registrations'),
            new AttendanceScopeService($_SESSION),
            new NullLogger(),
            new NameFormatterService()
        );
    }

    /** Liest den Zustands-Hash so aus, wie ihn das Formular mitschicken würde. */
    private function currentStateHash(): string
    {
        $response = $this->controller()->detail(
            $this->makeRequest('GET', '/registrations/' . $this->event->id),
            $this->makeResponse(),
            ['event_id' => (string) $this->event->id]
        );

        preg_match('#name="state_hash" value="([a-f0-9]*)"#', (string) $response->getBody(), $matches);
        $this->assertNotEmpty($matches, 'Das Vertretungsformular muss einen Zustands-Hash mitschicken.');

        return $matches[1];
    }

    private function registrationOf(User $user): ?EventRegistration
    {
        return EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Stand A: nur die erste Person hat geantwortet, ihre Begründung lautet
     * "<Kennung der zweiten Person>no". Stand B: beide haben mit "no"
     * geantwortet, beide ohne Begründung. Ohne Trennzeichen reihen sich beide
     * Stände zu derselben Zeichenkette.
     */
    public function testNoteCannotImitateAnotherRowInTheStateHash(): void
    {
        EventRegistration::create([
            'event_id' => (int) $this->event->id,
            'user_id' => (int) $this->first->id,
            'status' => EventRegistration::STATUS_NO,
            'note' => (string) $this->second->id . EventRegistration::STATUS_NO,
        ]);

        $staleHash = $this->currentStateHash();

        // Beide Mitglieder korrigieren sich selbst, nachdem der Verwalter die
        // Liste geladen hat - der Stand ist damit ein anderer.
        EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->first->id)
            ->update(['note' => null]);
        EventRegistration::create([
            'event_id' => (int) $this->event->id,
            'user_id' => (int) $this->second->id,
            'status' => EventRegistration::STATUS_NO,
        ]);

        $response = $this->controller()->saveProxy(
            $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', [
                'registration' => [
                    (string) $this->first->id => EventRegistration::STATUS_YES,
                    (string) $this->second->id => EventRegistration::STATUS_YES,
                ],
                'state_hash' => $staleHash,
            ]),
            $this->makeResponse(),
            ['event_id' => (string) $this->event->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty(
            $_SESSION['error'] ?? '',
            'Der veraltete Zustand muss erkannt werden, auch wenn eine Begründung eine weitere Zeile nachahmt.'
        );
        $this->assertSame(
            EventRegistration::STATUS_NO,
            $this->registrationOf($this->first)?->status,
            'Bei erkanntem Konflikt darf nichts geschrieben werden.'
        );
        $this->assertSame(
            EventRegistration::STATUS_NO,
            $this->registrationOf($this->second)?->status,
            'Bei erkanntem Konflikt darf nichts geschrieben werden.'
        );
    }
}
