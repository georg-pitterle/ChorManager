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
        $this->assertFalse($policy->canViewAllCandidates());
        $this->assertSame([], $policy->getAccessibleProjectIds());
    }

    public function testPolicyDeniesAccessToUserManagerWithoutProjectMemberPermission(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_project_members'] = false;

        $policy = new ProjectMemberPolicy();

        // can_manage_users no longer implies project-member access; the dedicated
        // can_manage_project_members permission is required.
        $this->assertFalse($policy->canViewMembers(999));
        $this->assertFalse($policy->canAddMember(999));
        $this->assertFalse($policy->canRemoveMember(999));
        $this->assertFalse($policy->canViewAllCandidates());
    }

    public function testProjectMemberManagerReachesProjectsWithoutOwnMembership(): void
    {
        // can_manage_project_members gilt projektuebergreifend und haengt nicht an
        // der eigenen Projektmitgliedschaft - sonst waere ein neu angelegtes Projekt
        // ohne Mitglieder fuer niemanden erreichbar (Henne-Ei-Problem).
        $_SESSION['user_id'] = 999;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_project_members'] = true;

        // Der Stub liefert das Projekt 42, in dem der Nutzer selbst kein Mitglied ist -
        // für das breite Recht umfasst getAccessibleProjectIds() alle Projekte, nicht
        // nur die eigenen. Der Beweis gegen die echte Datenbank steht in
        // ProjectAccessWithoutMembershipFeatureTest.
        $policy = self::getStubBuilder(ProjectMemberPolicy::class)
            ->onlyMethods(['getAccessibleProjectIds'])
            ->getStub();

        $policy->method('getAccessibleProjectIds')
            ->willReturn([42]);

        $this->assertTrue($policy->canViewMembers(42));
        $this->assertTrue($policy->canAddMember(42));
        $this->assertTrue($policy->canRemoveMember(42));
        $this->assertTrue($policy->canViewAllCandidates());

        // Eine Projekt-Id außerhalb der Liste bleibt auch für das breite Recht zu.
        $this->assertFalse($policy->canViewMembers(4711));
    }

    public function testVoiceGroupScopedRightStaysBoundToOwnProjects(): void
    {
        $_SESSION['user_id'] = 999;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;

        $policy = self::getStubBuilder(ProjectMemberPolicy::class)
            ->onlyMethods(['getAccessibleProjectIds'])
            ->getStub();

        $policy->method('getAccessibleProjectIds')
            ->willReturn([]);

        $this->assertFalse($policy->canViewMembers(42));
        $this->assertFalse($policy->canAddMember(42));
        $this->assertFalse($policy->canRemoveMember(42));
        $this->assertFalse($policy->canViewAllCandidates());
    }
}
