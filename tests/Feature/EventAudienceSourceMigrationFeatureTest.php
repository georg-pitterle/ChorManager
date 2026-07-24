<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceSourceMigrationFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testTableExists(): void
    {
        $this->assertTrue(Capsule::schema()->hasTable('event_audience_sources'));
    }

    public function testEventHasAudienceSourcesRelation(): void
    {
        $event = Event::query()->firstOrFail();
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Collection::class,
            $event->audienceSources
        );
    }

    public function testModelConstants(): void
    {
        $this->assertSame('project_members', EventAudienceSource::TYPE_PROJECT_MEMBERS);
        $this->assertSame('role', EventAudienceSource::TYPE_ROLE);
        $this->assertSame('user', EventAudienceSource::TYPE_USER);
        $this->assertSame('voice_group', EventAudienceSource::TYPE_VOICE_GROUP);
    }
}
