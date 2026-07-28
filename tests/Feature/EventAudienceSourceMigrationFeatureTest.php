<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAudienceSource;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceSourceMigrationFeatureTest extends TestCase
{
    use EventScopeFixtures;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        parent::tearDown();
    }

    public function testTableExists(): void
    {
        $this->assertTrue(Capsule::schema()->hasTable('event_audience_sources'));
    }

    public function testEventHasAudienceSourcesRelation(): void
    {
        $event = Event::create([
            'title' => 'Audience-Relation-Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(21, 0),
            'type' => 'Probe',
        ]);

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
