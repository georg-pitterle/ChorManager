<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilter;
use App\Models\Event;
use App\Models\EventSeries;
use App\Services\AttendanceScopeService;
use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\EventAudienceService;
use PHPUnit\Framework\TestCase;

/**
 * Termin-Zielgruppen als Filter: UND zwischen Kategorien, ODER zwischen Zeilen,
 * keine Vermischung der Merkmale verschiedener verwalteter Mitglieder.
 */
class EventAudienceFilterFeatureTest extends TestCase
{
    use FileFixtures;
    use AudienceFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function event(?int $seriesId = null): Event
    {
        return Event::create([
            'title' => 'Probe ' . bin2hex(random_bytes(3)),
            'starts_at' => '2030-01-01 19:00:00',
            'ends_at' => '2030-01-01 21:00:00',
            'type' => 'Probe',
            'series_id' => $seriesId,
        ]);
    }

    public function testSopranoAndProjectReachesOnlySopranosInProject(): void
    {
        $inBoth = $this->createMember('Sopran im Projekt');
        $sopranoOnly = $this->createMember('Sopran');
        $projectOnly = $this->createMember('Alt im Projekt');
        $soprano = $this->createVoiceGroupFor($inBoth);
        $sopranoOnly->voiceGroups()->attach($soprano->id);
        $project = $this->createProjectFor($inBoth);
        $projectOnly->projects()->attach($project->id);
        $event = $this->event();
        $this->giveAudience('event_id', (int) $event->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);

        $eligible = $event->eligibleUsersQuery()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $inBoth->id], $eligible);

        $service = new EventAudienceService();
        $this->assertTrue($service->visibleEventsQuery((int) $inBoth->id)->whereKey($event->id)->exists());
        $this->assertFalse($service->visibleEventsQuery((int) $sopranoOnly->id)->whereKey($event->id)->exists());
        $this->assertFalse($service->visibleEventsQuery((int) $projectOnly->id)->whereKey($event->id)->exists());
    }

    public function testManagedMembersAreNotMixed(): void
    {
        $manager = $this->createMember('Stimmführung');
        $sopranoWithoutProject = $this->createMember('Sopran');
        $altoInProject = $this->createMember('Alt');
        $soprano = $this->createVoiceGroupFor($sopranoWithoutProject);
        $project = $this->createProjectFor($altoInProject);
        $event = $this->event();
        $this->giveAudience('event_id', (int) $event->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);

        $managed = [(int) $sopranoWithoutProject->id, (int) $altoInProject->id];
        $scope = new class ($managed) extends AttendanceScopeService {
            /** @param list<int> $managed */
            public function __construct(private readonly array $managed)
            {
            }

            public function canManageOthers(): bool
            {
                return true;
            }

            public function getManageableUserIds(): array
            {
                return $this->managed;
            }
        };
        $_SESSION = ['user_id' => (int) $manager->id];

        $this->assertFalse($scope->canAccessEvent($event));
    }

    public function testProjectScopeFindsEventsWithProjectCondition(): void
    {
        $member = $this->createMember();
        $project = $this->createProjectFor($member);
        $combined = $this->event();
        $other = $this->event();
        $this->giveAudience('event_id', (int) $combined->id, [
            'role' => [(int) $this->createRoleFor($member)->id],
            'project' => [(int) $project->id],
        ]);
        $this->giveAudience('event_id', (int) $other->id, []);

        $ids = Event::query()->forProject((int) $project->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $combined->id], $ids);
        $this->assertSame([(int) $combined->id], $project->events()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function testEmptyFormIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new EventAudienceService())->readRows(['audience' => []]);
    }

    public function testHalfFilledRowIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        (new EventAudienceService())->readRows(['audience' => [['conditions' => []]]]);
    }

    public function testSeriesEventsGetOwnFiltersAndDeletingByQueryRemovesThem(): void
    {
        $series = EventSeries::create(['frequency' => 'weekly', 'recurrence_interval' => 1, 'end_date' => '2030-02-01']);
        $first = $this->event((int) $series->id);
        $second = $this->event((int) $series->id);
        $service = new EventAudienceService();
        foreach ([$first, $second] as $event) {
            $service->setAudience($event, [[]]);
        }
        $filterIds = AudienceFilter::query()->whereIn('event_id', [$first->id, $second->id])->pluck('id')->all();
        $this->assertCount(2, $filterIds);

        Event::query()->whereIn('id', [$first->id, $second->id])->delete();

        $this->assertSame(0, AudienceFilter::query()->whereIn('id', $filterIds)->count());
    }
}
