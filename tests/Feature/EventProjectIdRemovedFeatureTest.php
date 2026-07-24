<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventProjectIdRemovedFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testEventsTableHasNoProjectIdColumn(): void
    {
        $this->assertFalse(Capsule::schema()->hasColumn('events', 'project_id'));
    }

    public function testEventModelHasNoProjectRelation(): void
    {
        $this->assertFalse(method_exists(\App\Models\Event::class, 'project'));
    }
}
