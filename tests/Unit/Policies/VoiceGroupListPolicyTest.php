<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\VoiceGroupList;
use App\Policies\VoiceGroupListPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Wer Stimmgruppen-Listen sieht: das eigene Recht und die Mitgliedschaft in
 * genau der Stimmgruppe der Liste - beides, und sonst nichts.
 */
final class VoiceGroupListPolicyTest extends TestCase
{
    public function testTheRightAndTheVoiceGroupOpenTheList(): void
    {
        $policy = new VoiceGroupListPolicy(['user_id' => 7, 'can_keep_voice_group_lists' => true, 'voice_group_ids' => [3]]);

        $this->assertTrue($policy->canAccessList($this->listOf(3)));
    }

    public function testAForeignVoiceGroupStaysClosed(): void
    {
        $policy = new VoiceGroupListPolicy(['user_id' => 7, 'can_keep_voice_group_lists' => true, 'voice_group_ids' => [3]]);

        $this->assertFalse($policy->canAccessList($this->listOf(4)));
        $this->assertFalse($policy->canUseVoiceGroup(4));
    }

    public function testWithoutTheRightTheOwnVoiceGroupStaysClosed(): void
    {
        $policy = new VoiceGroupListPolicy(['user_id' => 7, 'voice_group_ids' => [3]]);

        $this->assertFalse($policy->canAccessList($this->listOf(3)));
        $this->assertSame([], $policy->accessibleVoiceGroupIds());
    }

    public function testOtherRightsDoNotOpenTheList(): void
    {
        foreach (['can_manage_own_voice_group', 'can_manage_users', 'can_manage_attendance_all'] as $right) {
            $policy = new VoiceGroupListPolicy(['user_id' => 7, $right => true, 'voice_group_ids' => [3]]);

            $this->assertFalse($policy->canAccessList($this->listOf(3)), $right);
        }
    }

    public function testWithoutAVoiceGroupNothingIsAccessible(): void
    {
        $policy = new VoiceGroupListPolicy(['user_id' => 7, 'can_keep_voice_group_lists' => true]);

        $this->assertSame([], $policy->accessibleVoiceGroupIds());
        $this->assertFalse($policy->canAccessList($this->listOf(3)));
    }

    public function testARepresentativeOfTwoVoiceGroupsReachesBoth(): void
    {
        $policy = new VoiceGroupListPolicy([
            'user_id' => 7,
            'can_keep_voice_group_lists' => 1,
            'voice_group_ids' => ['3', 5],
        ]);

        $this->assertSame([3, 5], $policy->accessibleVoiceGroupIds());
        $this->assertTrue($policy->canAccessList($this->listOf(3)));
        $this->assertTrue($policy->canAccessList($this->listOf(5)));
        $this->assertSame(7, $policy->currentUserId());
    }

    private function listOf(int $voiceGroupId): VoiceGroupList
    {
        $list = new VoiceGroupList();
        $list->voice_group_id = $voiceGroupId;

        return $list;
    }
}
