<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\User;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    private function cleanEvent(): Event
    {
        $event = Event::query()->firstOrFail();
        EventAudienceSource::where('event_id', $event->id)->delete();
        return $event->fresh();
    }

    public function testSetAndGetSourcesRoundTrip(): void
    {
        $project = Project::query()->firstOrFail();
        $event = $this->cleanEvent();
        $service = new EventAudienceService();

        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);

        $sources = $service->getSources($event->fresh());
        $this->assertSame(
            [['type' => 'project_members', 'reference_id' => (int) $project->id]],
            $sources
        );
    }

    public function testSetSourcesReplacesPrevious(): void
    {
        $project = Project::query()->firstOrFail();
        $user = User::where('is_active', 1)->firstOrFail();
        $event = $this->cleanEvent();
        $service = new EventAudienceService();

        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);
        $service->setSources($event->fresh(), [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $user->id],
        ]);

        $sources = $service->getSources($event->fresh());
        $this->assertSame(
            [['type' => 'user', 'reference_id' => (int) $user->id]],
            $sources
        );
    }

    public function testNormalizeRejectsUnknownTypeAndMissingReference(): void
    {
        $service = new EventAudienceService();
        $normalized = $service->normalizeSources([
            ['type' => 'nonsense', 'reference_id' => 5],
            ['type' => 'project_members', 'reference_id' => 0],
            ['type' => 'project_members', 'reference_id' => 999999],
        ]);

        $this->assertSame([], $normalized);
    }

    public function testNormalizeDeduplicates(): void
    {
        $project = Project::query()->firstOrFail();
        $service = new EventAudienceService();
        $normalized = $service->normalizeSources([
            ['type' => 'project_members', 'reference_id' => (int) $project->id],
            ['type' => 'project_members', 'reference_id' => (int) $project->id],
        ]);

        $this->assertCount(1, $normalized);
    }

    public function testIsUserEligibleForEmptyScopeIsTrue(): void
    {
        $event = $this->cleanEvent();
        $user = User::where('is_active', 1)->firstOrFail();
        $service = new EventAudienceService();

        $this->assertTrue($service->isUserEligible($event, (int) $user->id));
    }

    public function testVisibleEventsQueryIncludesEmptyScopeEvent(): void
    {
        $event = $this->cleanEvent();
        $user = User::where('is_active', 1)->firstOrFail();
        $service = new EventAudienceService();

        $ids = $service->visibleEventsQuery((int) $user->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $event->id, $ids);
    }

    public function testVisibleEventsQueryExcludesNonMatchingUserScope(): void
    {
        $users = User::where('is_active', 1)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $users);
        [$inScope, $outScope] = [$users[0], $users[1]];

        $event = $this->cleanEvent();
        $service = new EventAudienceService();
        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_USER, 'reference_id' => (int) $inScope->id],
        ]);

        $visibleForOut = $service->visibleEventsQuery((int) $outScope->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $visibleForIn = $service->visibleEventsQuery((int) $inScope->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains((int) $event->id, $visibleForOut);
        $this->assertContains((int) $event->id, $visibleForIn);
    }
}
