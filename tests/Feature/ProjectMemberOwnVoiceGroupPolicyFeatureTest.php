<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectMemberPolicy;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

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

    public function testScopedHolderWithoutOwnVoiceGroupReachesNothing(): void
    {
        // Ohne eigene Stimmgruppe trifft das beschränkte Recht auf niemanden:
        // die Kandidatenliste bleibt leer und jedes Zuordnen scheitert. Die
        // Besetzungsseite trotzdem zu öffnen wäre genau das reine Lese-Recht,
        // das es laut Klassenkommentar nicht geben soll - eine Sackgasse.
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;
        $_SESSION['voice_group_ids'] = [];

        $policy = $this->policyWithAccessibleProjects([42]);

        $this->assertFalse($policy->canViewMembers(42));
        $this->assertFalse($policy->canAddMember(42));
        $this->assertFalse($policy->canRemoveMember(42));
        $this->assertFalse($policy->canManageMember(42, [7]));
    }

    public function testBroadManagerStaysUnaffectedByAnEmptyOwnVoiceGroup(): void
    {
        // Das breite Recht hängt nicht an einer eigenen Stimmgruppe - sonst
        // käme eine Chorleitung ohne Stimmgruppe an kein Projekt mehr heran.
        $_SESSION['user_id'] = 5;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;
        $_SESSION['voice_group_ids'] = [];

        $policy = $this->policyWithAccessibleProjects([42]);

        $this->assertTrue($policy->canViewMembers(42));
        $this->assertTrue($policy->canManageMember(42, []));
    }

    public function testScopedHolderWithoutOwnVoiceGroupGetsNoAccessibleProjects(): void
    {
        // Gegen die echte Datenbank: die Projektliste des beschränkten Rechts
        // bleibt leer, solange keine eigene Stimmgruppe hinterlegt ist. Sonst
        // stünden unter /projects/members Projekte, in denen sich anschließend
        // niemand zuordnen lässt.
        Bootstrap::setupTestDatabase();
        $connection = Bootstrap::getCapsule()?->connection();
        $connection?->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(4));
            $project = Project::create(['name' => 'Stimmgruppenprojekt ' . $suffix]);
            $user = User::create([
                'email' => 'scoped_' . $suffix . '@example.test',
                'password' => PasswordHasher::hash('secret'),
                'first_name' => 'Sabine',
                'last_name' => 'Steiner',
                'is_active' => 1,
            ]);

            Capsule::table('project_users')->insert([
                'user_id' => (int) $user->id,
                'project_id' => (int) $project->id,
            ]);

            $_SESSION['user_id'] = (int) $user->id;
            $_SESSION['can_manage_project_members'] = false;
            $_SESSION['can_assign_own_voice_group_to_project'] = true;
            $_SESSION['voice_group_ids'] = [];

            $this->assertSame([], (new ProjectMemberPolicy())->getAccessibleProjectIds());

            // Mit eigener Stimmgruppe steht das eigene Projekt wie bisher drin.
            $_SESSION['voice_group_ids'] = [1];
            $this->assertSame([(int) $project->id], (new ProjectMemberPolicy())->getAccessibleProjectIds());
        } finally {
            if ($connection !== null && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
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
