<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilterCondition as C;
use App\Models\SubVoice;
use App\Services\Audience\AudienceFilterService;
use PHPUnit\Framework\TestCase;

/**
 * Regel der Zielgruppen-Filter: innerhalb einer Kategorie genügt ein Wert,
 * zwischen den Kategorien müssen alle zutreffen; ohne Bedingung trifft der
 * Filter alle.
 */
class AudienceFilterServiceFeatureTest extends TestCase
{
    use FileFixtures;

    private AudienceFilterService $filters;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
        $this->filters = new AudienceFilterService();
    }

    protected function tearDown(): void
    {
        $this->tearDownFileFixtures();
    }

    /**
     * @param array<string, list<int>> $conditions
     */
    private function filterMatches(int $userId, array $conditions): bool
    {
        $filter = $this->filters->create($conditions);
        $profile = $this->filters->profileOf($userId);
        $this->assertNotNull($profile);

        return $this->filters->matchingFilterIds($profile, [(int) $filter->id]) === [(int) $filter->id];
    }

    public function testEmptyFilterMatchesEveryone(): void
    {
        $this->assertTrue($this->filterMatches((int) $this->createMember()->id, []));
    }

    public function testOrWithinCategoryAndAcrossCategories(): void
    {
        $sopran = $this->createMember('Sopran');
        $soprano = $this->createVoiceGroupFor($sopran, 'Sopran');
        $project = $this->createProjectFor($sopran, 'Frühjahrskonzert');
        $alto = $this->createMember('Alt');
        $altGroup = $this->createVoiceGroupFor($alto, 'Alt');
        $alto->projects()->attach($project->id);
        $outsider = $this->createMember('Sopran außerhalb');
        $outsider->voiceGroups()->attach($soprano->id);

        $both = [
            C::CATEGORY_VOICE_GROUP => [(int) $soprano->id, (int) $altGroup->id],
            C::CATEGORY_PROJECT => [(int) $project->id],
        ];
        $this->assertTrue($this->filterMatches((int) $sopran->id, $both));
        $this->assertTrue($this->filterMatches((int) $alto->id, $both));
        $this->assertFalse($this->filterMatches((int) $outsider->id, $both), 'Sopran ohne Projekt.');

        $onlySoprano = [
            C::CATEGORY_VOICE_GROUP => [(int) $soprano->id],
            C::CATEGORY_PROJECT => [(int) $project->id],
        ];
        $this->assertFalse($this->filterMatches((int) $alto->id, $onlySoprano), 'Alt im Projekt.');
    }

    public function testEachCategoryOnItsOwn(): void
    {
        $member = $this->createMember();
        $role = $this->createRoleFor($member);
        $group = $this->createVoiceGroupFor($member);
        $sub = SubVoice::create(['name' => 'Sopran 1', 'voice_group_id' => $group->id]);
        $member->voiceGroups()->updateExistingPivot($group->id, ['sub_voice_id' => $sub->id]);
        $project = $this->createProjectFor($member);
        $other = $this->createMember('Andere');

        foreach (
            [
                [C::CATEGORY_ROLE, (int) $role->id],
                [C::CATEGORY_VOICE_GROUP, (int) $group->id],
                [C::CATEGORY_SUB_VOICE, (int) $sub->id],
                [C::CATEGORY_PROJECT, (int) $project->id],
                [C::CATEGORY_USER, (int) $member->id],
            ] as [$category, $id]
        ) {
            $this->assertTrue($this->filterMatches((int) $member->id, [$category => [$id]]), $category);
            $this->assertFalse($this->filterMatches((int) $other->id, [$category => [$id]]), $category . ' fremd');
        }
    }

    public function testDeletedReferenceBlocksButValidSiblingStillMatches(): void
    {
        $member = $this->createMember();
        $group = $this->createVoiceGroupFor($member);

        $this->assertFalse($this->filterMatches((int) $member->id, [C::CATEGORY_ROLE => [999999]]));
        $this->assertTrue($this->filterMatches((int) $member->id, [C::CATEGORY_VOICE_GROUP => [999999, (int) $group->id]]));
    }

    public function testMembersQueryCountsOnlyActiveMatches(): void
    {
        $active = $this->createMember('Aktiv');
        $role = $this->createRoleFor($active);
        $inactive = $this->createMember('Inaktiv');
        $inactive->roles()->attach($role->id);
        $inactive->is_active = 0;
        $inactive->save();

        $filter = $this->filters->create([C::CATEGORY_ROLE => [(int) $role->id]]);
        $ids = $this->filters->membersQuery((int) $filter->id)->pluck('users.id')
            ->map(fn ($id): int => (int) $id)->all();

        $this->assertSame([(int) $active->id], $ids);
    }

    public function testConditionsOfAndDelete(): void
    {
        $filter = $this->filters->create([C::CATEGORY_PROJECT => [7, 3], C::CATEGORY_ROLE => [2]]);
        $empty = $this->filters->create([]);
        $ids = [(int) $filter->id, (int) $empty->id];

        $this->assertSame(
            [(int) $filter->id => [C::CATEGORY_ROLE => [2], C::CATEGORY_PROJECT => [3, 7]], (int) $empty->id => []],
            $this->filters->conditionsOf($ids)
        );

        $this->filters->delete([(int) $filter->id]);
        $this->assertSame([(int) $empty->id => []], $this->filters->conditionsOf($ids));
    }
}
