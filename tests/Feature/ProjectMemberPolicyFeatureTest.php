<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Policies\ProjectMemberPolicy;
use PHPUnit\Framework\TestCase;

class ProjectMemberPolicyFeatureTest extends TestCase
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

    public function testPolicyDeniesAccessWhenNoPermissions(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_project_members'] = false;

        $policy = new ProjectMemberPolicy();

        $this->assertFalse($policy->canViewMembers(1));
        $this->assertFalse($policy->canAddMember(1));
        $this->assertFalse($policy->canRemoveMember(1));
        $this->assertFalse($policy->canViewAllCandidates(1));
        $this->assertSame([], $policy->getAccessibleProjectIds());
    }

    public function testPolicyGrantsAccessToGlobalAdmin(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_project_members'] = false;

        $policy = new ProjectMemberPolicy();

        // Global admin should have access regardless of project membership
        // Note: getAccessibleProjectIds() requires database access, so we only test the permission check methods
        $this->assertTrue($policy->canViewMembers(999));
        $this->assertTrue($policy->canAddMember(999));
        $this->assertTrue($policy->canRemoveMember(999));
        $this->assertTrue($policy->canViewAllCandidates(999));
    }

    public function testProjectMemberManagerWithoutAccessibleProjectsDeniesAllPermissions(): void
    {
        // When can_manage_project_members is true but the user has no accessible projects,
        // all permissions should be denied for any project not in the accessible list
        $_SESSION['user_id'] = 999;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_project_members'] = true;

        // Use a partial stub to simulate getAccessibleProjectIds() returning empty array
        // (which would happen if User::find() returns null or user has no projects)
        $policy = self::getStubBuilder(ProjectMemberPolicy::class)
            ->onlyMethods(['getAccessibleProjectIds'])
            ->getStub();

        $policy->method('getAccessibleProjectIds')
            ->willReturn([]);

        // All permission methods should return false for any project ID
        // when the project is not in the accessible projects list
        $this->assertFalse($policy->canViewMembers(42));
        $this->assertFalse($policy->canAddMember(42));
        $this->assertFalse($policy->canRemoveMember(42));
        $this->assertFalse($policy->canViewAllCandidates(42));
    }
}
