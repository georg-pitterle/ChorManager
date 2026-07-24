<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventEligibleUsersScopeFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    private function freshEvent(): Event
    {
        $event = Event::query()->firstOrFail();
        EventAudienceSource::where('event_id', $event->id)->delete();
        return $event->fresh();
    }

    public function testEmptySourcesMeansAllActiveUsers(): void
    {
        $event = $this->freshEvent();

        $this->assertSame(
            (int) User::where('is_active', 1)->count(),
            (int) $event->eligibleUsersQuery()->count()
        );
    }

    public function testProjectMembersSource(): void
    {
        $project = Project::query()->whereHas('users')->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => (int) $project->id,
        ]);

        $expected = User::where('is_active', 1)
            ->whereHas('projects', fn ($q) => $q->where('project_id', (int) $project->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $actual = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertNotSame([], $actual);
    }

    public function testRoleSource(): void
    {
        $role = Role::query()->whereHas('users')->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_ROLE,
            'reference_id' => (int) $role->id,
        ]);

        $expected = User::where('is_active', 1)
            ->whereHas('roles', fn ($q) => $q->where('role_id', (int) $role->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $actual = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function testVoiceGroupSource(): void
    {
        $voiceGroup = VoiceGroup::query()->whereHas('users')->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_VOICE_GROUP,
            'reference_id' => (int) $voiceGroup->id,
        ]);

        $expected = User::where('is_active', 1)
            ->whereHas('voiceGroups', fn ($q) => $q->where('voice_group_id', (int) $voiceGroup->id))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $actual = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function testUserSource(): void
    {
        $user = User::where('is_active', 1)->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_USER,
            'reference_id' => (int) $user->id,
        ]);

        $ids = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $user->id], $ids);
    }

    public function testMultipleSourcesUnionWithoutDuplicates(): void
    {
        $role = Role::query()->whereHas('users')->firstOrFail();
        $user = User::where('is_active', 1)->firstOrFail();
        $event = $this->freshEvent();
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_ROLE,
            'reference_id' => (int) $role->id,
        ]);
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_USER,
            'reference_id' => (int) $user->id,
        ]);

        $ids = $event->fresh()->eligibleUsersQuery()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertContains((int) $user->id, $ids);
    }
}
