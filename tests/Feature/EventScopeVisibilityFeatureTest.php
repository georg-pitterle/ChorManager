<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\User;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventScopeVisibilityFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
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
        $users = User::where('is_active', 1)->orderBy('id')->take(2)->get();
        [$in, $out] = [$users[0], $users[1]];
        $event = Event::query()->firstOrFail();
        EventAudienceSource::where('event_id', $event->id)->delete();

        $service = new EventAudienceService();
        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $in->id],
        ]);

        $this->assertTrue($service->isUserEligible($event->fresh(), (int) $in->id));
        $this->assertFalse($service->isUserEligible($event->fresh(), (int) $out->id));
    }
}
