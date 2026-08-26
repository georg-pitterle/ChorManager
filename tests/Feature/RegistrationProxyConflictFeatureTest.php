<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Vertretungseinträge sind dieselbe Mehrbenutzer-Situation wie die
 * Anwesenheitsliste: Zwei Verwalter öffnen dieselbe Liste, einer speichert
 * zuerst. Ohne Zustands-Hash überschreibt der zweite die Selbstauskunft des
 * Mitglieds kommentarlos. Zusätzlich muss sich ein Eintrag wieder auf "offen"
 * zurücknehmen lassen.
 */
final class RegistrationProxyConflictFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private Event $event;
    private User $manager;
    private User $member;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $this->event = Event::create([
            'title' => 'Probe Vertretungskonflikt',
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);

        $this->manager = $this->createUser();
        $this->member = $this->createUser();

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
            'email' => "proxy_conflict_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    private function controller(): RegistrationController
    {
        return new RegistrationController(
            $this->createAppTwig('/registrations'),
            new AttendanceScopeService(),
            new NullLogger(),
            new NameFormatterService()
        );
    }

    /**
     * @param array<int|string, string> $registrations
     */
    private function saveProxy(array $registrations, ?string $stateHash = null): \Psr\Http\Message\ResponseInterface
    {
        $body = ['registration' => $registrations];
        if ($stateHash !== null) {
            $body['state_hash'] = $stateHash;
        }

        return $this->controller()->saveProxy(
            $this->makeRequest('POST', '/registrations/' . $this->event->id . '/proxy', $body),
            $this->makeResponse(),
            ['event_id' => (string) $this->event->id]
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

    public function testStatusOpenRemovesAnExistingEntry(): void
    {
        EventRegistration::create([
            'event_id' => (int) $this->event->id,
            'user_id' => (int) $this->member->id,
            'status' => 'yes',
        ]);

        $this->saveProxy([(string) $this->member->id => 'open']);

        $this->assertNull($this->registrationOf($this->member), 'Ein Eintrag muss sich auf "offen" zurücknehmen lassen.');
    }

    public function testConcurrentChangeIsRejectedAndNothingIsWritten(): void
    {
        EventRegistration::create([
            'event_id' => (int) $this->event->id,
            'user_id' => (int) $this->member->id,
            'status' => 'yes',
        ]);

        $staleHash = $this->currentStateHash();

        // Das Mitglied korrigiert sich selbst, nachdem der Verwalter die Liste geladen hat.
        EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $this->member->id)
            ->update(['status' => 'no', 'note' => 'Doch verhindert']);

        $response = $this->saveProxy([(string) $this->member->id => 'yes'], $staleHash);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('no', $this->registrationOf($this->member)?->status);
        $this->assertNotEmpty($_SESSION['error'] ?? '');
    }

    public function testMatchingStateHashIsAccepted(): void
    {
        $freshHash = $this->currentStateHash();

        $this->saveProxy([(string) $this->member->id => 'yes'], $freshHash);

        $this->assertSame('yes', $this->registrationOf($this->member)?->status);
    }
}
