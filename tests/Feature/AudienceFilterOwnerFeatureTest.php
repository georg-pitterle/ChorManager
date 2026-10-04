<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilter;
use App\Models\Event;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\InvalidAudienceFilterException;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * Besitzerbezogene Auswertung: mehrere Filter eines Besitzers sind ODER,
 * gelöscht wird über den Fremdschlüssel des Besitzers.
 */
class AudienceFilterOwnerFeatureTest extends TestCase
{
    use FileFixtures;
    use AudienceFixtures;

    private AudienceFilterService $service;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->service = new AudienceFilterService();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    private function event(): Event
    {
        return Event::create([
            'title' => 'Probe ' . bin2hex(random_bytes(3)),
            'starts_at' => '2030-01-01 19:00:00',
            'ends_at' => '2030-01-01 21:00:00',
            'type' => 'Probe',
        ]);
    }

    public function testFiltersOfOneOwnerAreCombinedWithOr(): void
    {
        $soprano = $this->createMember('Sopran');
        $alto = $this->createMember('Alt');
        $other = $this->createMember('Bass');
        $sopranoGroup = $this->createVoiceGroupFor($soprano);
        $altoGroup = $this->createVoiceGroupFor($alto);
        $event = $this->event();

        $this->giveAudience('event_id', (int) $event->id, ['voice_group' => [(int) $sopranoGroup->id]], [
            'voice_group' => [(int) $altoGroup->id],
        ]);

        $ids = $this->service->membersQueryForOwner('event_id', (int) $event->id)->pluck('users.id')->all();
        $this->assertContains((int) $soprano->id, $ids);
        $this->assertContains((int) $alto->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function testOwnerWithoutFilterMatchesNobodyAndEmptyFilterMatchesAll(): void
    {
        $member = $this->createMember();
        $event = $this->event();
        $this->assertSame(0, $this->service->membersQueryForOwner('event_id', (int) $event->id)->count());

        $this->giveAudience('event_id', (int) $event->id, []);
        $ids = $this->service->membersQueryForOwner('event_id', (int) $event->id)->pluck('users.id')->all();
        $this->assertContains((int) $member->id, $ids);
    }

    public function testMatchingOwnerIdsUsesAndWithinAFilter(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);
        $project = $this->createProjectFor($member);
        $foreignProject = $this->createProjectFor($this->createMember());
        $fits = $this->event();
        $misses = $this->event();
        $this->giveAudience('event_id', (int) $fits->id, [
            'voice_group' => [(int) $group->id],
            'project' => [(int) $project->id],
        ]);
        $this->giveAudience('event_id', (int) $misses->id, [
            'voice_group' => [(int) $group->id],
            'project' => [(int) $foreignProject->id],
        ]);

        $profile = $this->service->profileOf((int) $member->id);
        $this->assertNotNull($profile);
        $matching = $this->service->matchingOwnerIds($profile, 'event_id');
        $this->assertContains((int) $fits->id, $matching);
        $this->assertNotContains((int) $misses->id, $matching);
    }

    public function testReplaceForOwnerSwapsAllFilters(): void
    {
        $event = $this->event();
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $this->giveAudience('event_id', (int) $event->id, [], ['role' => [(int) $role->id]]);

        $this->service->replaceForOwner('event_id', (int) $event->id, [['user' => [(int) $member->id]]]);

        $sets = $this->service->conditionSetsForOwners('event_id', [(int) $event->id]);
        $this->assertSame([['user' => [(int) $member->id]]], $sets[(int) $event->id]);
    }

    public function testDeletingOwnerByQueryRemovesFiltersAndConditions(): void
    {
        $event = $this->event();
        $this->giveAudience('event_id', (int) $event->id, ['user' => [(int) $this->createMember()->id]]);
        $filterIds = AudienceFilter::query()->where('event_id', $event->id)->pluck('id')->all();
        $this->assertNotSame([], $filterIds);

        Event::query()->whereIn('id', [(int) $event->id])->delete();

        $this->assertSame(0, AudienceFilter::query()->whereIn('id', $filterIds)->count());
        $this->assertSame(0, DB::table('audience_filter_conditions')->whereIn('audience_filter_id', $filterIds)->count());
    }

    public function testFilterWithoutOwnerIsRejectedByDatabase(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audience_filters')->insert(['created_at' => date('Y-m-d H:i:s')]);
    }

    public function testFilterWithTwoOwnersIsRejectedByDatabase(): void
    {
        $event = $this->event();
        $newsletter = \App\Models\Newsletter::create([
            'title' => 'Zwei Besitzer',
            'content_html' => '<p>x</p>',
            'status' => \App\Models\Newsletter::STATUS_DRAFT,
            'created_by' => (int) $this->createMember()->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audience_filters')->insert([
            'created_at' => date('Y-m-d H:i:s'),
            'event_id' => (int) $event->id,
            'newsletter_id' => (int) $newsletter->id,
        ]);
    }

    public function testUnknownOwnerColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->membersQueryForOwner('users.id; DROP', 1);
    }

    public function testProfilesOfLoadsEveryMemberSeparately(): void
    {
        $a = $this->createMember();
        $b = $this->createMember();
        $groupA = $this->createVoiceGroupFor($a);
        $projectB = $this->createProjectFor($b);

        $profiles = $this->service->profilesOf([(int) $a->id, (int) $b->id]);

        $this->assertTrue($profiles[(int) $a->id]->has('voice_group', (int) $groupA->id));
        $this->assertFalse($profiles[(int) $a->id]->has('project', (int) $projectB->id));
        $this->assertTrue($profiles[(int) $b->id]->has('project', (int) $projectB->id));
    }

    public function testNormalizeRowsMergesEqualRowsAndRejectsHalfFilledRow(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $normalizer = new AudienceFilterNormalizer();
        $rows = [
            ['conditions' => ['role' => [(string) $role->id]]],
            ['conditions' => ['role' => [(string) $role->id]]],
            ['all' => '1'],
        ];
        $this->assertSame([['role' => [(int) $role->id]], []], $normalizer->normalizeRows($rows));

        $this->expectException(InvalidAudienceFilterException::class);
        $normalizer->normalizeRows([['conditions' => []]]);
    }
}
