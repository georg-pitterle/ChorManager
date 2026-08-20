<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidAudienceSourcesException;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\User;
use App\Services\EventAudienceService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Legt alle Fixtures selbst an und rollt sie nach jedem Test zurueck, damit die
 * Tests nicht vom aktuellen Dev-Seed-Stand der Datenbank abhaengen.
 */
class EventAudienceServiceFeatureTest extends TestCase
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

    public function testSetAndGetSourcesRoundTrip(): void
    {
        $project = $this->createProject();
        $event = $this->createEvent();
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
        $project = $this->createProject();
        $user = $this->createUser();
        $event = $this->createEvent();
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
        $project = $this->createProject();
        $service = new EventAudienceService();
        $normalized = $service->normalizeSources([
            ['type' => 'project_members', 'reference_id' => (int) $project->id],
            ['type' => 'project_members', 'reference_id' => (int) $project->id],
        ]);

        $this->assertCount(1, $normalized);
    }

    public function testIsUserEligibleForEmptyScopeIsTrue(): void
    {
        $event = $this->createEvent();
        $user = $this->createUser();
        $service = new EventAudienceService();

        $this->assertTrue($service->isUserEligible($event, (int) $user->id));
    }

    public function testVisibleEventsQueryIncludesEmptyScopeEvent(): void
    {
        $event = $this->createEvent();
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

    /**
     * Ein Termin ohne Quellen gilt für alle Mitglieder. Werden beim Speichern
     * alle angegebenen Quellen verworfen (z. B. weil das Projekt gelöscht
     * wurde), dürfen die bisherigen Quellen nicht stillschweigend zu
     * "alle Mitglieder" werden.
     */
    public function testSetSourcesRefusesAnAudienceThatWouldSilentlyWidenToEveryone(): void
    {
        $project = $this->createProject();
        $event = $this->createEvent();
        $service = new EventAudienceService();

        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);

        $this->expectException(InvalidAudienceSourcesException::class);

        try {
            $service->setSources($event->fresh(), [
                ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => 999999],
            ]);
        } finally {
            $this->assertSame(
                [['type' => 'project_members', 'reference_id' => (int) $project->id]],
                $service->getSources($event->fresh()),
                'Die bisherige Zielgruppe muss unverändert bestehen bleiben.'
            );
        }
    }

    public function testSetSourcesStillAcceptsAnEmptyAudienceOnPurpose(): void
    {
        $project = $this->createProject();
        $event = $this->createEvent();
        $service = new EventAudienceService();

        $service->setSources($event, [
            ['type' => EventAudienceSource::TYPE_PROJECT_MEMBERS, 'reference_id' => (int) $project->id],
        ]);

        // Leere Eingabe heißt ausdrücklich "alle Mitglieder" und bleibt erlaubt.
        $service->setSources($event->fresh(), []);

        $this->assertSame([], $service->getSources($event->fresh()));
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
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
