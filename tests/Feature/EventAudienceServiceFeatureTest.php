<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\EventAudienceService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Legt alle Fixtures selbst an und rollt sie nach jedem Test zurueck, damit die
 * Tests nicht vom aktuellen Dev-Seed-Stand der Datenbank abhaengen.
 */
class EventAudienceServiceFeatureTest extends TestCase
{
    use AudienceFixtures;
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

    public function testSetAndReadAudienceRoundTrip(): void
    {
        $project = $this->createProject();
        $event = $this->createEvent();
        $service = new EventAudienceService();

        $service->setAudience($event, [['project' => [(int) $project->id]]]);

        $this->assertSame([['project' => [(int) $project->id]]], $service->conditionSets($event->fresh()));
    }

    public function testSetAudienceReplacesPrevious(): void
    {
        $project = $this->createProject();
        $user = $this->createUser();
        $event = $this->createEvent();
        $service = new EventAudienceService();

        $this->giveAudience('event_id', (int) $event->id, ['project' => [(int) $project->id]]);
        $service->setAudience($event->fresh(), [['user' => [(int) $user->id]]]);

        $this->assertSame([['user' => [(int) $user->id]]], $service->conditionSets($event->fresh()));
    }

    public function testReadRowsRejectsACategoryWhoseValuesAreAllGone(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new EventAudienceService())->readRows([
            'audience' => [['conditions' => ['project' => ['999999999']]]],
        ]);
    }

    public function testReadRowsMergesEqualRows(): void
    {
        $project = $this->createProject();
        $rows = (new EventAudienceService())->readRows([
            'audience' => [
                ['conditions' => ['project' => [(string) $project->id]]],
                ['conditions' => ['project' => [(string) $project->id]]],
            ],
        ]);

        $this->assertCount(1, $rows);
    }

    public function testEveryoneRowMakesEveryActiveMemberEligible(): void
    {
        $event = $this->openToEveryone($this->createEvent());
        $user = $this->createUser();

        $this->assertTrue((new EventAudienceService())->isUserEligible($event, (int) $user->id));
    }

    public function testEventWithoutAnyRowMatchesNobody(): void
    {
        $event = $this->createEvent();
        $user = $this->createUser();

        $this->assertFalse((new EventAudienceService())->isUserEligible($event, (int) $user->id));
    }

    public function testVisibleEventsQueryIncludesEveryoneEvent(): void
    {
        $event = $this->openToEveryone($this->createEvent());
        $user = $this->createUser();
        $service = new EventAudienceService();

        $ids = $service->visibleEventsQuery((int) $user->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $event->id, $ids);
    }

    public function testVisibleEventsQueryExcludesNonMatchingUserScope(): void
    {
        $inScope = $this->createUser();
        $outScope = $this->createUser();

        $event = $this->createEvent();
        $service = new EventAudienceService();
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $inScope->id]]);

        $visibleForOut = $service->visibleEventsQuery((int) $outScope->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $visibleForIn = $service->visibleEventsQuery((int) $inScope->id)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains((int) $event->id, $visibleForOut);
        $this->assertContains((int) $event->id, $visibleForIn);
    }

    private function createProject(): Project
    {
        return Project::create([
            'name' => 'Audience-Test-Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => Carbon::now()->subMonth()->toDateString(),
            'end_date' => Carbon::now()->addMonth()->toDateString(),
        ]);
    }

    private function createEvent(): Event
    {
        return Event::create([
            'title' => 'Audience-Test-Termin ' . bin2hex(random_bytes(4)),
            'starts_at' => Carbon::now()->addDays(5)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(5)->setTime(21, 0),
            'type' => 'Probe',
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Audience',
            'last_name' => 'Testperson',
            'email' => 'audience-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
