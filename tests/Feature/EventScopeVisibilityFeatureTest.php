<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\User;
use App\Services\EventAudienceService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventScopeVisibilityFeatureTest extends TestCase
{
    use EventScopeFixtures;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        $_SESSION = [];
    }

    public function testExportUsesVisibleEventsQueryOnly(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/EventController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('visibleEventsQuery', $source);
        $this->assertStringNotContainsString('getAccessibleCalendarEventsForUser', $source);
    }

    public function testUserOutsideScopeIsNotEligible(): void
    {
        $in = $this->createUser();
        $out = $this->createUser();
        $event = Event::create([
            'title' => 'Sichtbarkeits-Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(6)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(6)->setTime(21, 0),
            'type' => 'Probe',
        ]);

        $service = new EventAudienceService();
        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $in->id],
        ]);

        $this->assertTrue($service->isUserEligible($event->fresh(), (int) $in->id));
        $this->assertFalse($service->isUserEligible($event->fresh(), (int) $out->id));
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Sicht',
            'last_name' => 'Testperson',
            'email' => 'sicht-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
