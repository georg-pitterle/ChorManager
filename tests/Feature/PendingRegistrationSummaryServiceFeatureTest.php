<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\PendingRegistrationSummaryService;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

class PendingRegistrationSummaryServiceFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function createUserInVoiceGroup(): array
    {
        $group = VoiceGroup::create(['name' => 'Pending-Reg-Scope']);
        $user = User::create([
            'first_name' => 'Pending',
            'last_name' => 'Anmelder',
            'email' => 'pending-reg@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => true,
        ]);
        self::$capsule?->table('user_voice_groups')->insert([
            'user_id' => $user->id,
            'voice_group_id' => $group->id,
        ]);

        return [$user, $group];
    }

    private function createOpenRegistrationEvent(VoiceGroup $group): Event
    {
        $event = Event::create([
            'title' => 'Offene Anmeldung ' . uniqid(),
            'starts_at' => Carbon::now()->addDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(10)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
            'registration_deadline' => Carbon::now()->addDays(9),
        ]);
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $group->id,
        ]);

        return $event;
    }

    public function testSummaryCountsPendingYesNoAsDeltaOverBaseline(): void
    {
        [$user, $group] = $this->createUserInVoiceGroup();
        $service = new PendingRegistrationSummaryService();

        $baseline = $service->forUser((int) $user->id)
            ?? ['total' => 0, 'pending' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0];

        $this->createOpenRegistrationEvent($group); // pending (no response)
        $yesEvent = $this->createOpenRegistrationEvent($group);
        $noEvent = $this->createOpenRegistrationEvent($group);
        $maybeEvent = $this->createOpenRegistrationEvent($group);

        EventRegistration::create([
            'event_id' => $yesEvent->id,
            'user_id' => $user->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $user->id,
        ]);
        EventRegistration::create([
            'event_id' => $noEvent->id,
            'user_id' => $user->id,
            'status' => EventRegistration::STATUS_NO,
            'updated_by' => $user->id,
        ]);
        EventRegistration::create([
            'event_id' => $maybeEvent->id,
            'user_id' => $user->id,
            'status' => EventRegistration::STATUS_MAYBE,
            'updated_by' => $user->id,
        ]);

        $summary = $service->forUser((int) $user->id);
        $this->assertNotNull($summary);

        $this->assertSame($baseline['total'] + 4, $summary['total']);
        $this->assertSame($baseline['pending'] + 1, $summary['pending']);
        $this->assertSame($baseline['yes'] + 1, $summary['yes']);
        $this->assertSame($baseline['no'] + 1, $summary['no']);
        $this->assertSame($baseline['maybe'] + 1, $summary['maybe']);
    }

    public function testClosedRegistrationEventIsIgnored(): void
    {
        [$user, $group] = $this->createUserInVoiceGroup();
        $service = new PendingRegistrationSummaryService();

        $baseline = $service->forUser((int) $user->id)
            ?? ['total' => 0, 'pending' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0];

        // Registration disabled -> not counted.
        $disabled = Event::create([
            'title' => 'Ohne Anmeldung',
            'starts_at' => Carbon::now()->addDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(10)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => false,
        ]);
        EventAudienceSource::create([
            'event_id' => $disabled->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $group->id,
        ]);

        // Deadline already passed -> not "open" -> not counted.
        $pastDeadline = Event::create([
            'title' => 'Frist abgelaufen',
            'starts_at' => Carbon::now()->addDays(10)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(10)->setTime(21, 0),
            'type' => 'Probe',
            'registration_enabled' => true,
            'registration_deadline' => Carbon::now()->subDay(),
        ]);
        EventAudienceSource::create([
            'event_id' => $pastDeadline->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $group->id,
        ]);

        $summary = $service->forUser((int) $user->id)
            ?? ['total' => 0, 'pending' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0];

        $this->assertSame($baseline['total'], $summary['total']);
    }

    public function testUserOutsideScopeDoesNotCountEvent(): void
    {
        [$user, $group] = $this->createUserInVoiceGroup();
        $otherGroup = VoiceGroup::create(['name' => 'Pending-Reg-Other']);
        $service = new PendingRegistrationSummaryService();

        $baseline = $service->forUser((int) $user->id)
            ?? ['total' => 0, 'pending' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0];

        // Open registration event scoped to a voice group the user is NOT in.
        $this->createOpenRegistrationEvent($otherGroup);

        $summary = $service->forUser((int) $user->id)
            ?? ['total' => 0, 'pending' => 0, 'yes' => 0, 'no' => 0, 'maybe' => 0];

        $this->assertSame($baseline['total'], $summary['total']);
    }
}
