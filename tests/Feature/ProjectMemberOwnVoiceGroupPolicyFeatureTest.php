<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Policies\ProjectMemberPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers the voice-group-scoped project assignment right
 * (can_assign_own_voice_group_to_project): a role may add and remove
 * members of its own voice group to/from its own projects, without the
 * broad can_manage_project_members right that reaches every voice group.
 */
class ProjectMemberOwnVoiceGroupPolicyFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @param array<int> $accessibleIds
     */
    private function policyWithAccessibleProjects(array $accessibleIds): ProjectMemberPolicy
    {
        $policy = self::getStubBuilder(ProjectMemberPolicy::class)
            ->onlyMethods(['getAccessibleProjectIds'])
            ->getStub();

        $policy->method('getAccessibleProjectIds')->willReturn($accessibleIds);

        return $policy;
    }

    public function testOwnVoiceGroupHolderCanViewMembersOfAccessibleProject(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;
        $_SESSION['voice_group_ids'] = [7];

        $policy = $this->policyWithAccessibleProjects([42]);

        $this->assertTrue($policy->canViewMembers(42));
        $this->assertTrue($policy->canAddMember(42));
        $this->assertTrue($policy->canRemoveMember(42));
        // The candidate list must stay restricted to the own voice group.
        $this->assertFalse($policy->canViewAllCandidates());
    }

    public function testOwnVoiceGroupHolderDeniedForForeignProject(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;
        $_SESSION['voice_group_ids'] = [7];

        $policy = $this->policyWithAccessibleProjects([42]);

        $this->assertFalse($policy->canViewMembers(99));
    }

    public function testOwnVoiceGroupHolderMayManageOnlyMembersSharingTheirVoiceGroup(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;
        $_SESSION['voice_group_ids'] = [7, 8];

        $policy = $this->policyWithAccessibleProjects([42]);

        // Candidate/member shares voice group 8 -> allowed.
        $this->assertTrue($policy->canManageMember(42, [8]));
        // Candidate/member is in a foreign voice group only -> denied.
        $this->assertFalse($policy->canManageMember(42, [3]));
        // A member without any voice group cannot be touched by a scoped holder.
        $this->assertFalse($policy->canManageMember(42, []));
    }

    public function testBroadManagerReachesEveryVoiceGroupAndAllCandidates(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;
        $_SESSION['voice_group_ids'] = [7];

        $policy = $this->policyWithAccessibleProjects([42]);

        $this->assertTrue($policy->canViewAllCandidates());
        $this->assertTrue($policy->canManageMember(42, [3]));
        $this->assertTrue($policy->canManageMember(42, []));
    }

    public function testNoRelevantRightDeniesEverything(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;
        $_SESSION['voice_group_ids'] = [7];

        $policy = $this->policyWithAccessibleProjects([42]);

        $this->assertFalse($policy->canViewMembers(42));
        $this->assertFalse($policy->canManageMember(42, [7]));
    }
}
