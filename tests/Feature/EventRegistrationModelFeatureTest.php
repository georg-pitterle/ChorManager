<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventRegistrationModelFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Ohne Rollback blieben Termin, Person und Anmeldung liegen und verfälschten spätere
        // Tests, die Bestände zählen.
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testMigrationDefinesRegistrationSchema(): void
    {
        $migrationPath = dirname(__DIR__)
            . '/../db/migrations/20260709120000_create_event_registrations.php';
        $this->assertFileExists($migrationPath);

        $content = file_get_contents($migrationPath);
        $this->assertIsString($content);
        $this->assertStringContainsString("'event_registrations'", $content);
        $this->assertStringContainsString("'registration_enabled'", $content);
        $this->assertStringContainsString("'registration_deadline'", $content);
        $this->assertStringContainsString("'registration_reminder_sent_at'", $content);
        $this->assertStringContainsString("'attendance_required'", $content);
        $this->assertStringContainsString("['event_id', 'user_id'], ['unique' => true]", $content);
    }

    public function testEventRegistrationModelRoundTrip(): void
    {
        // Eigene Person statt einer beliebigen aus dem Bestand, damit der Test auch auf einer
        // frisch aufgesetzten Datenbank läuft.
        $user = User::create([
            'email' => 'registration_model_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Rudi',
            'last_name' => 'Rückmeldung',
            'is_active' => 1,
        ]);
        $event = Event::create([
            'title' => 'Testprobe Anmeldung',
            'starts_at' => Carbon::now()->addDays(7)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(7)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventRegistration::STATUS_YES,
            'note' => null,
            'updated_by' => $user->id,
        ]);

        $fresh = EventRegistration::find($registration->id);
        $this->assertSame('yes', $fresh->status);
        $this->assertSame((int) $user->id, (int) $fresh->user->id);
        $this->assertSame((int) $event->id, (int) $fresh->event->id);
        $this->assertSame((int) $user->id, (int) $fresh->updatedBy->id);
        $this->assertNotNull($fresh->created_at);
        $this->assertCount(1, $event->registrations()->get());
        $this->assertTrue($user->eventRegistrations()->count() >= 1);

        $registration->delete();
        $event->delete();
    }

    public function testRegistrationDeadlineHelpers(): void
    {
        $event = new Event([
            'starts_at' => Carbon::now()->addDays(3),
            'registration_enabled' => true,
        ]);
        $this->assertTrue($event->isRegistrationOpen());
        $this->assertSame(
            $event->starts_at->toDateTimeString(),
            $event->registrationDeadlineAt()->toDateTimeString()
        );

        $event->registration_deadline = Carbon::now()->subHour();
        $this->assertFalse($event->isRegistrationOpen());

        $event->registration_deadline = Carbon::now()->addHour();
        $this->assertTrue($event->isRegistrationOpen());

        $disabled = new Event([
            'starts_at' => Carbon::now()->addDays(3),
            'registration_enabled' => false,
        ]);
        $this->assertFalse($disabled->isRegistrationOpen());

        $past = new Event([
            'starts_at' => Carbon::now()->subDay(),
            'registration_enabled' => true,
        ]);
        $this->assertFalse($past->isRegistrationOpen());
    }
}
